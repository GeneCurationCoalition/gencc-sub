<?php

namespace Tests\Unit;

use App\Models\Disease;
use App\Services\DiseaseResolution;
use App\Services\DiseaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDiseaseWorld;
use Tests\TestCase;

/**
 * The disease resolution policy, asserted step by step over the shared fixture
 * world:
 *
 *   1. a MONDO term skos:exactMatch-es the submitted code;
 *   2. Orphadata asserts an exact, validated MONDO equivalent;
 *   3. Orphadata asserts an exact OMIM reference MONDO exact-matches;
 *   4. otherwise the identifier is rejected.
 *
 * An OMIM identifier gets step 1 only, which is what makes the mapping
 * reciprocal: OMIM's source asserts nothing to reciprocate with.
 */
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
            'status' => Disease::STATUS_ACTIVE,
        ]);

        $this->assertNull($mondoDisease->mondo_id);
        $this->assertEquals(Disease::TYPE_MONDO, $mondoDisease->type);
    }

    /**
     * A MONDO row records the OMIM and Orphanet identifiers it exact-matches,
     * both as arrays.  Orphanet used to be a last-wins scalar, which could keep
     * only one of the 43 terms' several exact matches.
     */
    public function test_mondo_xrefs_hold_exact_matches_as_arrays()
    {
        $this->seedDiseaseWorld();

        $mondo = Disease::curie('MONDO:0000002')->first();

        $this->assertSame(['600001', '600004'], (array) $mondo->xrefs->exact_omim);
        $this->assertSame(['700001', '700002', '700500'], (array) $mondo->xrefs->exact_orphanet);
    }

    /**
     * An Orphanet row records what Orphadata asserts about it, and nothing about
     * what MONDO asserts.
     */
    public function test_orphanet_xrefs_hold_only_its_own_assertions()
    {
        $this->seedDiseaseWorld();

        $orphanet = Disease::curie('Orphanet:700300')->first();

        $this->assertSame(['MONDO:0000003'], (array) $orphanet->xrefs->exact_mondo);
        $this->assertSame([], (array) $orphanet->xrefs->exact_omim);
    }

    /**
     * Every resolution step, over the shared fixture world: each step, each way
     * an identifier can fail, and each accepted spelling of Orphanet.
     *
     * @dataProvider resolutionCases
     */
    public function test_resolves_each_step(string $submitted, ?string $expectedOriginal, ?string $expectedMondo, ?string $expectedVia): void
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
        $exact = DiseaseResolution::VIA_MONDO_EXACT_MATCH;
        $orphaExact = DiseaseResolution::VIA_ORPHANET_EXACT_MATCH;
        $bridge = DiseaseResolution::VIA_OMIM_BRIDGE;

        $cases = [
            // A MONDO term is its own original and its own normalized target
            'MONDO self' => ['MONDO:0000001', 'MONDO:0000001', 'MONDO:0000001', $mondoSelf],
            'MONDO lowercase prefix' => ['mondo:0000001', 'MONDO:0000001', 'MONDO:0000001', $mondoSelf],
            'MONDO deprecated is allowed' => ['MONDO:0000004', 'MONDO:0000004', 'MONDO:0000004', $mondoSelf],
            'MONDO removed is excluded' => ['MONDO:0000005', null, null, null],
            'MONDO soft-deleted is excluded' => ['MONDO:0000006', null, null, null],
            'MONDO unknown' => ['MONDO:0009999', null, null, null],

            // OMIM: step 1 only, which is what enforces reciprocity
            'OMIM MONDO exact-matches' => ['OMIM:600001', 'OMIM:600001', 'MONDO:0000002', $exact],
            'OMIM with no record of its own' => ['OMIM:600004', null, 'MONDO:0000002', $exact],
            'OMIM on a deprecated MONDO term' => ['OMIM:600044', null, 'MONDO:0000004', $exact],
            'OMIM no MONDO term claims' => ['OMIM:600100', null, null, null],
            'OMIM reached only by an Orphanet bridge is not resolved' => ['OMIM:600900', null, null, null],
            'OMIM unknown' => ['OMIM:609999', null, null, null],
            'OMIM extra token is discarded' => ['OMIM:600001:extra', 'OMIM:600001', 'MONDO:0000002', $exact],

            // Namespaces this policy no longer loads or accepts
            'DOID' => ['DOID:800001', null, null, null],
            'GARD' => ['GARD:810001', null, null, null],
            'MEDGEN' => ['MEDGEN:820001', null, null, null],
            'UMLS' => ['UMLS:C830001', null, null, null],

            // Not identifiers we resolve
            'empty' => ['', null, null, null],
            'bare number' => ['600001', null, null, null],
            'no prefix separator' => ['MONDO', null, null, null],
            'unknown ontology' => ['FOO:123', null, null, null],
        ];

        // Orphanet, asserted for every accepted spelling and casing
        $orphanetCases = [
            'step 1, MONDO exact match' => ['700001', 'Orphanet:700001', 'MONDO:0000002', $exact],
            'step 1, no record of its own' => ['700044', null, 'MONDO:0000004', $exact],
            'step 1 wins over step 2' => ['700002', 'Orphanet:700002', 'MONDO:0000002', $exact],
            'step 1 on a deprecated Orphanet term' => ['700500', 'Orphanet:700500', 'MONDO:0000002', $exact],
            'step 2, Orphadata asserts the equivalent' => ['700300', 'Orphanet:700300', 'MONDO:0000003', $orphaExact],
            'step 3, via an exact OMIM reference' => ['700700', 'Orphanet:700700', 'MONDO:0000007', $bridge],
            'step 4, nothing maps it' => ['700200', null, null, null],
            'asserted MONDO term is not in the table' => ['700600', null, null, null],
            'OMIM reference no MONDO term claims' => ['700800', null, null, null],
            'removed' => ['700400', null, null, null],
            'unknown' => ['709999', null, null, null],

            // Ambiguity fails closed at every step
            'two MONDO terms exact-match it' => ['700888', null, null, null],
            'asserts two MONDO equivalents' => ['700999', null, null, null],
            'reaches two MONDO terms by bridge' => ['700777', null, null, null],
        ];

        foreach ($orphanetCases as $label => [$number, $original, $mondo, $via]) {
            foreach (['Orphanet', 'ORPHANET', 'ORPHA', 'orpha', 'oRpHa'] as $prefix) {
                $cases["Orphanet {$label} ({$prefix}:)"] = [$prefix.':'.$number, $original, $mondo, $via];
            }
        }

        return $cases;
    }

    /**
     * There is one policy.  The stricter-for-submission split is gone, because
     * exactness and reciprocity are now properties of what is stored, so no
     * caller can be handed a weaker answer than another.
     */
    public function test_one_policy_for_every_caller(): void
    {
        $this->seedDiseaseWorld();

        $resolver = new DiseaseResolver();

        // What used to resolve permissively but not for submission
        $this->assertNull($resolver->resolve('OMIM:600100'));

        // What used to resolve to itself for an upload
        $this->assertNull($resolver->resolve('Orphanet:700200'));
    }

    /**
     * Issue 132: an Orphanet identifier resolves the same whichever accepted
     * prefix the submitter wrote.
     */
    public function test_orphanet_prefix_spellings_all_resolve(): void
    {
        $this->seedDiseaseWorld();

        foreach (['Orphanet:700001', 'ORPHANET:700001', 'orphanet:700001', 'ORPHA:700001', 'orpha:700001'] as $submitted) {
            $resolution = (new DiseaseResolver())->resolve($submitted);

            $this->assertNotNull($resolution, "'{$submitted}' should resolve");
            $this->assertSame('Orphanet:700001', $resolution->original?->curie);
            $this->assertSame('MONDO:0000002', $resolution->mondo?->curie);
        }
    }
}
