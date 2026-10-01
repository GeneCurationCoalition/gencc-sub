<?php

namespace Tests\Feature;

use App\Models\Disease;
use App\Services\DiseaseMappingAmbiguity;
use App\Services\DiseaseResolution;
use App\Services\DiseaseResolver;
use Tests\Support\Seeders\SyntheticDiseaseValidationFixturesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyntheticDiseaseValidationFixturesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_existing_rows_and_is_safe_to_run_again(): void
    {
        $existing = Disease::factory()->mondo()->create([
            'curie' => 'MONDO:9999001',
            'name' => 'FIXTURE MONDO candidate 1',
            'xrefs' => ['outdated' => true],
        ]);
        $deleted = Disease::factory()->orphanet()->create([
            'curie' => 'Orphanet:999902',
            'name' => 'FIXTURE Orphanet direct ambiguity',
        ]);
        $deleted->delete();

        $this->seed(SyntheticDiseaseValidationFixturesSeeder::class);
        $firstIds = Disease::query()
            ->whereIn('curie', $this->fixtureCuries())
            ->orderBy('curie')
            ->pluck('id', 'curie')
            ->all();

        $this->assertCount(6, $firstIds);
        $this->assertSame($existing->id, $firstIds['MONDO:9999001']);
        $this->assertSame($deleted->id, $firstIds['Orphanet:999902']);
        $this->assertSame('SYNTHETIC TEST candidate 1', Disease::curie('MONDO:9999001')->value('name'));
        $this->assertSame(
            'SYNTHETIC TEST deprecated candidate 2',
            Disease::curie('MONDO:9999002')->value('deprecated_name')
        );
        $this->assertNull(Disease::withTrashed()->curie('Orphanet:999902')->value('deleted_at'));

        $resolver = new DiseaseResolver();
        $this->assertAmbiguity(
            $resolver->resolveDetailed('OMIM:999901'),
            DiseaseResolution::VIA_MONDO_EXACT_MATCH,
            ['MONDO:9999001', 'MONDO:9999002']
        );
        $this->assertAmbiguity(
            $resolver->resolveDetailed('Orphanet:999902'),
            DiseaseResolution::VIA_ORPHANET_EXACT_MATCH,
            ['MONDO:9999001', 'MONDO:9999002']
        );
        $this->assertAmbiguity(
            $resolver->resolveDetailed('Orphanet:999903'),
            DiseaseResolution::VIA_OMIM_BRIDGE,
            ['MONDO:9999001', 'MONDO:9999002', 'MONDO:9999003']
        );

        $unique = $resolver->resolveDetailed('OMIM:999904');
        $this->assertInstanceOf(DiseaseResolution::class, $unique);
        $this->assertNull($unique->original);
        $this->assertSame('MONDO:9999003', $unique->mondo->curie);
        $this->assertSame(
            'MONDO:9999005',
            Disease::curie('MONDO:9999004')->firstOrFail()->xrefs->replaced_by
        );

        $this->seed(SyntheticDiseaseValidationFixturesSeeder::class);

        $this->assertSame(
            $firstIds,
            Disease::query()
                ->whereIn('curie', $this->fixtureCuries())
                ->orderBy('curie')
                ->pluck('id', 'curie')
                ->all()
        );
    }

    /** @param array<int, string> $expectedCuries */
    private function assertAmbiguity(mixed $result, string $step, array $expectedCuries): void
    {
        $this->assertInstanceOf(DiseaseMappingAmbiguity::class, $result);
        $this->assertSame($step, $result->step);
        $this->assertSame(
            $expectedCuries,
            array_map(fn (Disease $disease) => $disease->curie, $result->candidates)
        );
    }

    /** @return array<int, string> */
    private function fixtureCuries(): array
    {
        return [
            'MONDO:9999001',
            'MONDO:9999002',
            'MONDO:9999003',
            'MONDO:9999004',
            'Orphanet:999902',
            'Orphanet:999903',
        ];
    }
}
