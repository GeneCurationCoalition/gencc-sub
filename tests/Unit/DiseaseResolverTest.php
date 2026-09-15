<?php

namespace Tests\Unit;

use App\Models\Disease;
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
