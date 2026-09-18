<?php

namespace Tests\Unit;

use App\Models\Classification;
use App\Models\Disease;
use App\Models\Gene;
use App\Models\Inheritance;
use App\Models\Submission;
use App\Services\DiseaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Support\SeedsDiseaseWorld;

/**
 * Phase 2 of an upload: the disease references Submission::load_from_json()
 * writes for a row.
 *
 * This is where issue 132 surfaced.  Every row of a 1,566 row Orphanet file
 * passed upload validation and then landed with
 * "Invalid Disease ID - no MONDO mapping found", because the row processor used
 * its own hand-built copy of the resolution rules and the copy keyed Orphanet
 * entries 'ORPHA:83471' while diseases.curie stores 'Orphanet:83471'.
 */
class SubmissionDiseaseResolutionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDiseaseWorld;

    protected Disease $mondo;

    protected Disease $orphanetMapped;

    protected Disease $orphanetUnmapped;

    protected function setUp(): void
    {
        parent::setUp();

        Gene::factory()->create(['hgnc_id' => 'HGNC:5', 'symbol' => 'A1BG']);

        Inheritance::create([
            'curie' => 'HP:0000006',
            'name' => 'Autosomal dominant',
            'description' => 'Test inheritance',
            'abbreviation' => 'AD',
            'type' => Inheritance::TYPE_MOI,
            'status' => Inheritance::STATUS_ACTIVE,
        ]);

        Classification::create([
            'curie' => 'GENCC:100001',
            'name' => 'Definitive',
            'description' => 'Test classification',
            'abbreviation' => 'DEF',
            'type' => Classification::TYPE_CLASSIFICATION,
            'status' => Classification::STATUS_ACTIVE,
        ]);

        $this->mondo = Disease::factory()->mondo()->withXrefs(['omim_id' => [], 'orpha_id' => ['83471'], 'replaced_by' => null])
            ->create(['curie' => 'MONDO:0000001']);

        $this->orphanetMapped = Disease::factory()->orphanet()->create(['curie' => 'Orphanet:83471']);

        $this->orphanetUnmapped = Disease::factory()->orphanet()->create(['curie' => 'Orphanet:723146']);
    }

    /**
     * Both prefix spellings, and both with the resolver an upload threads in
     * and without one (the fallback a single API submission takes).
     */
    public function test_orphanet_row_resolves_to_original_and_mondo(): void
    {
        foreach (['Orphanet:83471', 'ORPHA:83471', 'ORPHANET:83471', 'orpha:83471'] as $submitted) {
            foreach ($this->resolverModes() as $mode => $lookupCaches) {
                $submission = new Submission();
                $result = $submission->load_from_json($this->submissionPacket($submitted), $lookupCaches);

                $this->assertTrue($result, "'{$submitted}' ({$mode}) reported: ".json_encode($result));
                $this->assertEquals($this->orphanetMapped->id, $submission->original_disease_id, "'{$submitted}' ({$mode})");
                $this->assertEquals($this->mondo->id, $submission->disease_id, "'{$submitted}' ({$mode})");
            }
        }
    }

    /**
     * An Orphanet term with no exact MONDO equivalent is rejected: the row is
     * created with no disease and carries a blocking disease_curie_id error
     * naming the code that was submitted, which the job UI shows and
     * JobStateMachine::submit() blocks on.
     *
     * This is the case that used to resolve to the Orphanet term itself.
     */
    public function test_orphanet_row_without_mondo_is_rejected(): void
    {
        foreach (['Orphanet:723146', 'ORPHA:723146'] as $submitted) {
            foreach ($this->resolverModes() as $mode => $lookupCaches) {
                $submission = new Submission();
                $result = $submission->load_from_json($this->submissionPacket($submitted), $lookupCaches);

                $this->assertIsArray($result, "'{$submitted}' ({$mode}) should report errors");
                $this->assertArrayHasKey('disease_curie_id', $result);
                $this->assertStringContainsString($submitted, $result['disease_curie_id'],
                    'The error must name the code that was submitted');
                $this->assertNull($submission->disease_id, "'{$submitted}' ({$mode}) stores no disease");
                $this->assertNull($submission->original_disease_id, "'{$submitted}' ({$mode}) stores no original disease");
            }
        }
    }

    public function test_ambiguous_disease_reports_candidates_and_preserves_the_submitted_id(): void
    {
        self::seedJuvenileAbsenceMappings(false);

        foreach ($this->resolverModes() as $mode => $lookupCaches) {
            $submission = new Submission();
            $result = $submission->load_from_json($this->submissionPacket('ORPHA:1941'), $lookupCaches);

            $this->assertIsArray($result, $mode);
            $this->assertStringContainsString("Disease ID 'ORPHA:1941' cannot be mapped uniquely", $result['disease_curie_id']);
            $this->assertStringContainsString('MONDO:0011876 — obsolete juvenile absence epilepsy [deprecated]', $result['disease_curie_id']);
            $this->assertStringContainsString('MONDO:0800453 — juvenile absence epilepsy', $result['disease_curie_id']);
            $this->assertStringNotContainsString('MONDO:0020772', $result['disease_curie_id']);
            $this->assertNull($submission->disease_id);
            $this->assertNull($submission->original_disease_id);
            $this->assertSame('ORPHA:1941', $submission->submission_data->disease->id);
        }
    }

    /**
     * A MONDO term is its own original.
     */
    public function test_mondo_row_resolves_to_itself(): void
    {
        foreach ($this->resolverModes() as $mode => $lookupCaches) {
            $submission = new Submission();
            $result = $submission->load_from_json($this->submissionPacket('MONDO:0000001'), $lookupCaches);

            $this->assertTrue($result, "({$mode}) reported: ".json_encode($result));
            $this->assertEquals($this->mondo->id, $submission->original_disease_id);
            $this->assertEquals($this->mondo->id, $submission->disease_id);
        }
    }

    /**
     * An unresolvable identifier errors and stores no disease.
     */
    public function test_unknown_disease_id_reports_an_error(): void
    {
        foreach ($this->resolverModes() as $mode => $lookupCaches) {
            $submission = new Submission();
            $result = $submission->load_from_json($this->submissionPacket('Orphanet:999999'), $lookupCaches);

            $this->assertIsArray($result, "({$mode}) should report errors");
            $this->assertArrayHasKey('disease_curie_id', $result);
            $this->assertNull($submission->disease_id, "({$mode}) stores no disease");
        }
    }

    public function test_record_validation_uses_the_same_url_rules_as_portal_edits(): void
    {
        $packet = $this->submissionPacket('MONDO:0000001');
        $packet->report->ext_url = 'not-a-url';
        $packet->criteria->url = 'also-not-a-url';

        $result = (new Submission())->load_from_json($packet);

        $this->assertSame('Invalid Report URL', $result['report_url']);
        $this->assertSame('Invalid Criteria URL', $result['criteria_url']);
    }

    public function test_record_validation_rejects_non_web_urls(): void
    {
        $packet = $this->submissionPacket('MONDO:0000001');
        $packet->report->ext_url = 'ftp://example.com/report';
        $packet->criteria->url = 'ftp://example.com/criteria';

        $result = (new Submission())->load_from_json($packet);

        $this->assertSame('Invalid Report URL', $result['report_url']);
        $this->assertSame('Invalid Criteria URL', $result['criteria_url']);
    }

    public function test_missing_criteria_url_is_a_record_error(): void
    {
        $packet = $this->submissionPacket('MONDO:0000001');
        $packet->criteria->url = '';

        $result = (new Submission())->load_from_json($packet);

        $this->assertSame('Missing Criteria URL', $result['criteria_url']);
    }

    /**
     * Every reference field that does not resolve is left empty, with an error
     * naming what was submitted.  No stand-in record is stored: HP:0000005 in
     * particular is a real "Unknown" answer, not a placeholder.
     */
    public function test_unresolved_reference_fields_store_nothing(): void
    {
        // The rows that used to be stored as stand-ins exist, as in production
        Gene::factory()->create(['hgnc_id' => '', 'symbol' => '-']);
        Inheritance::create([
            'curie' => 'HP:0000005',
            'name' => 'Unknown',
            'description' => 'Test inheritance',
            'abbreviation' => 'Unknown',
            'type' => Inheritance::TYPE_MOI,
            'status' => Inheritance::STATUS_ACTIVE,
        ]);

        foreach ($this->resolverModes() as $mode => $lookupCaches) {
            $packet = $this->submissionPacket('Orphanet:999999');
            $packet->gene->id = 'HGNC:99999999';
            $packet->moi->id = 'HP:9999999';
            $packet->classification->id = 'GENCC:999999';

            $submission = new Submission();
            $result = $submission->load_from_json($packet, $lookupCaches);

            $this->assertSame([
                'gene_hgnc_id' => "Invalid HGNC ID 'HGNC:99999999'",
                'disease_curie_id' => "No MONDO term found for Disease ID 'Orphanet:999999' (unknown ID, or no exact MONDO match)",
                'moi_curie_id' => "Invalid MOI ID 'HP:9999999'",
                'classification_curie_id' => "Invalid Classification ID 'GENCC:999999'",
            ], array_intersect_key($result, array_flip(['gene_hgnc_id', 'disease_curie_id', 'moi_curie_id', 'classification_curie_id'])), $mode);

            foreach (['gene_id', 'disease_id', 'original_disease_id', 'inheritance_id', 'classification_id'] as $column) {
                $this->assertNull($submission->{$column}, "({$mode}) {$column}");
            }
        }

        // A missing value is reported as missing
        $packet = $this->submissionPacket('MONDO:0000001');
        unset($packet->gene, $packet->moi);
        $result = (new Submission())->load_from_json($packet);
        $this->assertSame('Missing HGNC ID', $result['gene_hgnc_id']);
        $this->assertSame('Missing MOI ID', $result['moi_curie_id']);
    }

    /**
     * The two ways load_from_json gets a resolver.
     *
     * @return array<string, array|null>
     */
    private function resolverModes(): array
    {
        return [
            'threaded resolver' => ['disease_resolver' => new DiseaseResolver()],
            'database fallback' => null,
        ];
    }

    /**
     * The minimal API submission packet load_from_json() consumes.
     */
    private function submissionPacket(string $diseaseId): object
    {
        return json_decode(json_encode([
            'submission_label' => 'TEST001',
            'gene' => ['id' => 'HGNC:5'],
            'disease' => ['id' => $diseaseId],
            'moi' => ['id' => 'HP:0000006'],
            'classification' => ['id' => 'GENCC:100001'],
            'report' => ['display_date' => '2024-01-15', 'ext_url' => 'https://example.com/report'],
            'criteria' => ['url' => 'https://example.com/criteria'],
        ]));
    }
}
