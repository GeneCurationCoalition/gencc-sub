<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Disease;
use App\Models\Submission;
use App\Services\DiseaseResolution;
use App\Services\DiseaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDiseaseWorld;

class DiseaseUpdateRefactoringTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDiseaseWorld;

    /**
     * Test that MONDO diseases have null mondo_id
     */
    public function test_mondo_diseases_have_null_mondo_id()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $this->assertNull($mondoDisease->mondo_id);
        $this->assertEquals(Disease::TYPE_MONDO, $mondoDisease->type);
    }

    /**
     * Test that OMIM diseases can have mondo_id
     */
    public function test_omim_diseases_can_have_mondo_id()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_ACTIVE,
            'xrefs' => ['omim_id' => ['615438']]
        ]);

        $omimDisease = Disease::factory()->create([
            'type' => Disease::TYPE_OMIM,
            'curie' => 'OMIM:615438',
            'mondo_id' => $mondoDisease->id,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $this->assertEquals($mondoDisease->id, $omimDisease->mondo_id);
        $this->assertEquals(Disease::TYPE_OMIM, $omimDisease->type);
    }

    /**
     * Test mondoDisease relationship
     */
    public function test_omim_disease_belongs_to_mondo()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $omimDisease = Disease::factory()->create([
            'type' => Disease::TYPE_OMIM,
            'curie' => 'OMIM:615438',
            'mondo_id' => $mondoDisease->id,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $linkedMondo = $omimDisease->mondoDisease;

        $this->assertNotNull($linkedMondo);
        $this->assertEquals($mondoDisease->id, $linkedMondo->id);
        $this->assertEquals('MONDO:0000001', $linkedMondo->curie);
    }

    /**
     * Test equivalentDiseases relationship
     */
    public function test_mondo_has_many_equivalent_diseases()
    {
        $mondoDisease = Disease::factory()->create([
            'type' => Disease::TYPE_MONDO,
            'curie' => 'MONDO:0000001',
            'mondo_id' => null,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $omimDisease = Disease::factory()->create([
            'type' => Disease::TYPE_OMIM,
            'curie' => 'OMIM:615438',
            'mondo_id' => $mondoDisease->id,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $orphanetDisease = Disease::factory()->create([
            'type' => Disease::TYPE_ORPHANET,
            'curie' => 'Orphanet:464724',
            'mondo_id' => $mondoDisease->id,
            'status' => Disease::STATUS_ACTIVE
        ]);

        $equivalents = $mondoDisease->equivalentDiseases;

        $this->assertCount(2, $equivalents);
        $this->assertTrue($equivalents->contains($omimDisease));
        $this->assertTrue($equivalents->contains($orphanetDisease));
    }

    /**
     * Every resolution strategy, over the shared fixture world: each strategy,
     * each way an identifier can fail to resolve, and each accepted spelling of
     * Orphanet.
     *
     * @dataProvider resolutionCases
     */
    public function test_resolves_each_strategy(string $submitted, ?string $expectedOriginal, ?string $expectedMondo, ?string $expectedVia): void
    {
        $this->seedDiseaseWorld();

        $resolution = (new DiseaseResolver())->resolve($submitted);

        if ($expectedMondo === null) {
            $this->assertNull($resolution, "Expected '{$submitted}' not to resolve");

            return;
        }

        $this->assertNotNull($resolution, "Expected '{$submitted}' to resolve");
        $this->assertSame($expectedOriginal, $resolution->original?->curie);
        $this->assertSame($expectedMondo, $resolution->mondo?->curie);
        $this->assertSame($expectedVia, $resolution->via);
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?string, 3: ?string}>
     */
    public static function resolutionCases(): array
    {
        $mondoSelf = DiseaseResolution::VIA_MONDO_SELF;
        $fk = DiseaseResolution::VIA_EQUIVALENCE_FK;
        $xref = DiseaseResolution::VIA_EQUIVALENCE_XREF;
        $orphaSelf = DiseaseResolution::VIA_ORPHANET_SELF;

        $cases = [
            // A MONDO term is its own original and its own normalized target
            'MONDO self' => ['MONDO:0000001', 'MONDO:0000001', 'MONDO:0000001', $mondoSelf],
            'MONDO lowercase prefix' => ['mondo:0000001', 'MONDO:0000001', 'MONDO:0000001', $mondoSelf],
            'MONDO deprecated is allowed' => ['MONDO:0000004', 'MONDO:0000004', 'MONDO:0000004', $mondoSelf],
            'MONDO removed is excluded' => ['MONDO:0000005', null, null, null],
            'MONDO soft-deleted is excluded' => ['MONDO:0000006', null, null, null],
            'MONDO unknown' => ['MONDO:0009999', null, null, null],

            // OMIM
            'OMIM via FK' => ['OMIM:600100', 'OMIM:600100', 'MONDO:0000003', $fk],
            'OMIM via xref, record present' => ['OMIM:600001', 'OMIM:600001', 'MONDO:0000002', $xref],
            'OMIM via xref, no record of its own' => ['OMIM:600004', null, 'MONDO:0000002', $xref],
            'OMIM via xref on a deprecated MONDO term' => ['OMIM:600044', null, 'MONDO:0000004', $xref],
            'OMIM removed does not follow its FK' => ['OMIM:600900', null, null, null],
            'OMIM unknown' => ['OMIM:609999', null, null, null],
            'OMIM extra token is discarded' => ['OMIM:600001:extra', 'OMIM:600001', 'MONDO:0000002', $xref],

            // Ontologies with no records of their own in the diseases table
            'DOID via xref' => ['DOID:800001', null, 'MONDO:0000002', $xref],
            'GARD via xref' => ['GARD:810001', null, 'MONDO:0000002', $xref],
            'MEDGEN via xref' => ['MEDGEN:820001', null, 'MONDO:0000002', $xref],
            'UMLS via xref' => ['UMLS:C830001', null, 'MONDO:0000002', $xref],
            'DOID only on a soft-deleted MONDO row' => ['DOID:800002', null, null, null],
            'DOID unknown' => ['DOID:899999', null, null, null],

            // Not identifiers we resolve
            'empty' => ['', null, null, null],
            'bare number' => ['600001', null, null, null],
            'no prefix separator' => ['MONDO', null, null, null],
            'unknown ontology' => ['FOO:123', null, null, null],
        ];

        // Orphanet, asserted for every accepted spelling and casing
        $orphanetCases = [
            'via FK' => ['700100', 'Orphanet:700100', 'MONDO:0000003', $fk],
            'via xref' => ['700001', 'Orphanet:700001', 'MONDO:0000002', $xref],
            'via xref, no record of its own' => ['700044', null, 'MONDO:0000004', $xref],
            'with no MONDO equivalent resolves to itself' => ['700200', 'Orphanet:700200', 'Orphanet:700200', $orphaSelf],
            'deprecated with no MONDO equivalent' => ['700300', null, null, null],
            'removed' => ['700400', null, null, null],
            'FK to a deprecated MONDO term' => ['700404', 'Orphanet:700404', 'MONDO:0000004', $fk],
            'FK to a removed MONDO term falls back to itself' => ['700500', 'Orphanet:700500', 'Orphanet:700500', $orphaSelf],
            'unknown' => ['709999', null, null, null],
        ];

        foreach ($orphanetCases as $label => [$number, $original, $mondo, $via]) {
            foreach (['Orphanet', 'ORPHANET', 'ORPHA', 'orpha', 'oRpHa'] as $prefix) {
                $cases["Orphanet {$label} ({$prefix}:)"] = [$prefix.':'.$number, $original, $mondo, $via];
            }
        }

        return $cases;
    }

    /**
     * The submission guard: an OMIM identifier that only reaches MONDO through
     * the FK is resolvable, but not submittable.  Those FKs were inferred
     * transitively rather than asserted by MONDO.
     */
    public function test_omim_fk_only_mapping_is_rejected_for_submission(): void
    {
        $this->seedDiseaseWorld();

        $resolver = new DiseaseResolver();

        // Resolvable
        $this->assertSame('MONDO:0000003', $resolver->resolve('OMIM:600100')?->mondo->curie);

        // But not submittable
        $this->assertNull($resolver->resolve('OMIM:600100', true));

        // An OMIM identifier MONDO does assert in its xrefs is submittable
        $this->assertSame('MONDO:0000002', $resolver->resolve('OMIM:600001', true)?->mondo->curie);
    }

    /**
     * The guard is deliberately not extended to Orphanet: there, a missing xref
     * means the xref parser cannot represent the mapping, not that we inferred
     * it.
     */
    public function test_orphanet_fk_only_mapping_is_accepted_for_submission(): void
    {
        $this->seedDiseaseWorld();

        $resolver = new DiseaseResolver();

        $this->assertSame('MONDO:0000003', $resolver->resolve('Orphanet:700100', true)?->mondo->curie);
        $this->assertSame('MONDO:0000003', $resolver->resolve('ORPHA:700100', true)?->mondo->curie);
    }

    /**
     * Issue 132: an Orphanet identifier resolves the same whichever accepted
     * prefix the submitter wrote.
     */
    public function test_orphanet_prefix_spellings_all_resolve(): void
    {
        $this->seedDiseaseWorld();

        foreach (['Orphanet:700001', 'ORPHANET:700001', 'orphanet:700001', 'ORPHA:700001', 'orpha:700001'] as $submitted) {
            $resolution = (new DiseaseResolver())->resolve($submitted, true);

            $this->assertNotNull($resolution, "'{$submitted}' should resolve");
            $this->assertSame('Orphanet:700001', $resolution->original?->curie);
            $this->assertSame('MONDO:0000002', $resolution->mondo?->curie);
        }
    }
}
