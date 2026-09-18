<?php

namespace Tests\Unit;

use App\Models\Disease;
use App\Services\DiseaseMappingAmbiguity;
use App\Services\DiseaseResolution;
use App\Services\DiseaseResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDiseaseWorld;
use Tests\TestCase;

/**
 * DiseaseResolver over the shared fixture world.
 *
 * Issue 132 was caused by disease resolution being implemented three times, in
 * copies that fell out of agreement.  There is now one implementation, so the
 * remaining ways to get a wrong answer are inside it: a memoized lookup keyed
 * differently from a fresh one, or the xref index disagreeing with the row it
 * was built from.  These tests target those.
 */
class DiseaseResolverTest extends TestCase
{
    use RefreshDatabase;
    use SeedsDiseaseWorld;

    /** @var array<string, Disease> */
    protected array $world = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->world = self::seedDiseaseWorld();
    }

    /**
     * One instance that has already resolved everything must answer every
     * input exactly as a fresh instance does.  An upload resolves hundreds of
     * identifiers through one resolver; this is what makes the first and the
     * last row see the same rules.
     */
    public function test_memoized_answers_match_fresh_ones(): void
    {
        $warm = new DiseaseResolver();

        foreach (self::diseaseWorldInputs() as $input) {
            $warm->resolve($input);
        }

        foreach (self::diseaseWorldInputs() as $input) {
            $this->assertSame(
                $this->describe((new DiseaseResolver())->resolve($input)),
                $this->describe($warm->resolve($input)),
                "Memoized resolver disagrees on '{$input}'"
            );
        }
    }

    /**
     * The fixture world must actually exercise each step and each failure
     * mode, or the test above passes on an empty world.
     */
    public function test_inputs_cover_every_step(): void
    {
        $resolver = new DiseaseResolver();

        $seen = [];
        $unresolved = 0;

        foreach (self::diseaseWorldInputs() as $input) {
            $resolution = $resolver->resolve($input);

            if ($resolution === null) {
                $unresolved++;
            } else {
                $seen[$resolution->via] = ($seen[$resolution->via] ?? 0) + 1;
            }
        }

        $this->assertArrayHasKey(DiseaseResolution::VIA_MONDO_SELF, $seen);
        $this->assertArrayHasKey(DiseaseResolution::VIA_MONDO_EXACT_MATCH, $seen);
        $this->assertArrayHasKey(DiseaseResolution::VIA_ORPHANET_EXACT_MATCH, $seen);
        $this->assertArrayHasKey(DiseaseResolution::VIA_OMIM_BRIDGE, $seen);
        $this->assertGreaterThan(10, $unresolved);
    }

    /**
     * Resolution only ever normalizes to a MONDO term.  The Orphanet
     * self-fallback is gone, so nothing else can reach submissions.disease_id.
     */
    public function test_every_resolution_targets_a_mondo_term(): void
    {
        $resolver = new DiseaseResolver();

        foreach (self::diseaseWorldInputs() as $input) {
            $resolution = $resolver->resolve($input);

            if ($resolution !== null) {
                $this->assertSame(Disease::TYPE_MONDO, $resolution->mondo->type, "'{$input}' resolved to a non-MONDO term");
            }
        }
    }

    public function test_real_orphanet_assertions_use_the_higher_priority_match(): void
    {
        $world = self::seedJuvenileAbsenceMappings();
        $result = (new DiseaseResolver())->resolveDetailed('Orphanet:1941');

        $this->assertInstanceOf(DiseaseResolution::class, $result);
        $this->assertSame($world['current']->id, $result->mondo->id);
        $this->assertSame(DiseaseResolution::VIA_MONDO_EXACT_MATCH, $result->via);
    }

    public function test_orphanet_ambiguity_stops_before_the_omim_bridge(): void
    {
        self::seedJuvenileAbsenceMappings(false);
        $resolver = new DiseaseResolver();

        foreach (['ORPHA:1941', 'Orphanet:1941', 'ORPHA:1941'] as $input) {
            $result = $resolver->resolveDetailed($input);
            $this->assertCandidates($result, DiseaseResolution::VIA_ORPHANET_EXACT_MATCH, ['MONDO:0011876', 'MONDO:0800453']);
            $message = $result->message($input);
            $this->assertStringContainsString($input, $message);
            $this->assertStringContainsString('obsolete juvenile absence epilepsy [deprecated]', $message);
            $this->assertStringNotContainsString('MONDO:0020772', $message);
            $this->assertNull($resolver->resolve($input));
            $this->assertNull($resolver->resolveDetailed('Orphanet:999999'));
            $this->assertInstanceOf(DiseaseResolution::class, $resolver->resolveDetailed('MONDO:0000001'));
        }
    }

    public function test_mondo_side_ambiguity_stops_before_an_orphanet_fallback(): void
    {
        $world = self::seedJuvenileAbsenceMappings();
        $world['obsolete']->update(['xrefs' => ['orpha_id' => ['1941']]]);
        $world['orphanet']->update(['xrefs' => ['mondo_id' => ['MONDO:0800453'], 'omim_id' => ['607631']]]);
        $resolver = new DiseaseResolver();

        for ($i = 0; $i < 2; $i++) {
            $this->assertCandidates($resolver->resolveDetailed('Orphanet:1941'), DiseaseResolution::VIA_MONDO_EXACT_MATCH,
                ['MONDO:0011876', 'MONDO:0800453']);
            $this->assertNull($resolver->resolve('Orphanet:1941'));
        }
    }

    public function test_ambiguous_omim_bridge_keeps_all_candidates_alongside_a_unique_bridge(): void
    {
        $world = self::seedJuvenileAbsenceMappings(false);
        $world['obsolete']->update(['xrefs' => ['omim_id' => ['607631']]]);
        $world['current']->update(['xrefs' => ['omim_id' => ['607632']]]);
        $world['orphanet']->update(['xrefs' => ['omim_id' => ['607632', '607631']]]);
        $resolver = new DiseaseResolver();

        for ($i = 0; $i < 2; $i++) {
            $this->assertCandidates($resolver->resolveDetailed('OMIM:607631'), DiseaseResolution::VIA_MONDO_EXACT_MATCH,
                ['MONDO:0011876', 'MONDO:0020772']);
            $this->assertCandidates($resolver->resolveDetailed('Orphanet:1941'), DiseaseResolution::VIA_OMIM_BRIDGE,
                ['MONDO:0011876', 'MONDO:0020772', 'MONDO:0800453']);
            $this->assertNull($resolver->resolve('Orphanet:1941'));
        }
    }

    public function test_separate_omim_bridges_with_distinct_targets_are_ambiguous(): void
    {
        $this->assertCandidates((new DiseaseResolver())->resolveDetailed('Orphanet:700777'), DiseaseResolution::VIA_OMIM_BRIDGE,
            ['MONDO:0000002', 'MONDO:0000007']);
    }

    public function test_multiple_references_to_the_same_mondo_are_not_ambiguous(): void
    {
        $world = self::seedJuvenileAbsenceMappings(false);
        $world['bridge']->update(['xrefs' => ['omim_id' => ['607631', '607632']]]);
        $world['orphanet']->update(['xrefs' => ['omim_id' => ['607632', '607631', '607631']]]);

        $result = (new DiseaseResolver())->resolveDetailed('Orphanet:1941');
        $this->assertInstanceOf(DiseaseResolution::class, $result);
        $this->assertSame($world['bridge']->id, $result->mondo->id);
        $this->assertSame(DiseaseResolution::VIA_OMIM_BRIDGE, $result->via);
    }

    private function assertCandidates($result, string $step, array $curies): void
    {
        $this->assertInstanceOf(DiseaseMappingAmbiguity::class, $result);
        $this->assertSame($step, $result->step);
        $this->assertSame($curies, array_map(fn (Disease $candidate) => $candidate->curie, $result->candidates));
    }

    /**
     * A comparable, printable form of a resolution.
     */
    private function describe(?DiseaseResolution $resolution): string
    {
        if ($resolution === null) {
            return 'null';
        }

        return sprintf(
            'original=%s mondo=%s via=%s',
            $resolution->original?->curie ?? '-',
            $resolution->mondo?->curie ?? '-',
            $resolution->via
        );
    }
}
