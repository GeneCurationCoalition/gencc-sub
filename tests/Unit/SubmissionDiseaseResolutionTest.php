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

        $this->mondo = Disease::factory()->mondo()->withXrefs(['exact_orphanet' => ['83471']])
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
     * created against the placeholder disease and carries a blocking
     * disease_curie_id error naming the code that was submitted, which the job
     * UI shows and JobStateMachine::submit() blocks on.
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
                $this->assertEquals($this->mondo->id, $submission->disease_id, "'{$submitted}' ({$mode}) falls back to the placeholder");
            }
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
     * An unresolvable identifier still errors, and falls back to the
     * placeholder disease.
     */
    public function test_unknown_disease_id_reports_an_error(): void
    {
        foreach ($this->resolverModes() as $mode => $lookupCaches) {
            $submission = new Submission();
            $result = $submission->load_from_json($this->submissionPacket('Orphanet:999999'), $lookupCaches);

            $this->assertIsArray($result, "({$mode}) should report errors");
            $this->assertArrayHasKey('disease_curie_id', $result);
            $this->assertEquals($this->mondo->id, $submission->disease_id, "({$mode}) falls back to MONDO:0000001");
        }
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
        ]));
    }
}
