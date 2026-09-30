<?php

namespace Tests\Support\Seeders;

use App\Models\Disease;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local/test-only fixtures for the F09-F13 disease validation cases.
 *
 * This class lives in the test tree and is excluded from production images.
 * From a local checkout with development dependencies, run after update:diseases:
 *
 *   php artisan db:seed --class='Tests\Support\Seeders\SyntheticDiseaseValidationFixturesSeeder'
 */
class SyntheticDiseaseValidationFixturesSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])
            && getenv('ALLOW_SYNTHETIC_DISEASE_FIXTURES') !== '1') {
            throw new RuntimeException(
                'Refusing to install synthetic diseases outside local/testing. '
                .'Set ALLOW_SYNTHETIC_DISEASE_FIXTURES=1 for an intentional staging run.'
            );
        }

        DB::transaction(function () {
            $this->assertTermsAreAbsent(['OMIM:999904', 'MONDO:9999005']);

            foreach ($this->fixtures() as $curie => $attributes) {
                $matches = Disease::withTrashed()->where('curie', $curie)->get();
                if ($matches->count() > 1) {
                    throw new RuntimeException("Cannot upsert {$curie}: duplicate disease rows exist.");
                }

                /** @var Disease $disease */
                $disease = $matches->first() ?? new Disease(['curie' => $curie]);
                if ($disease->exists && $disease->trashed()) {
                    $disease->restore();
                }

                $disease->fill($attributes);
                $disease->saveOrFail();
            }
        });

        $this->command?->info('Installed 6 synthetic disease-validation fixtures.');
    }

    /** @param array<int, string> $curies */
    private function assertTermsAreAbsent(array $curies): void
    {
        $present = Disease::query()->whereIn('curie', $curies)->pluck('curie')->all();
        if ($present !== []) {
            throw new RuntimeException(
                'Synthetic validation cases require these terms to be absent: '.implode(', ', $present)
            );
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function fixtures(): array
    {
        return [
            // F09: two MONDO terms exact-match the same OMIM identifier.
            'MONDO:9999001' => $this->mondo(
                'SYNTHETIC TEST candidate 1',
                ['999901']
            ),
            'MONDO:9999002' => $this->mondo(
                'SYNTHETIC TEST candidate 2',
                ['999901'],
                Disease::STATUS_DEPRECATED,
                'SYNTHETIC TEST deprecated candidate 2'
            ),

            // F10: Orphadata asserts two MONDO equivalents; its OMIM bridge
            // would find candidate 3, but the earlier ambiguous step wins.
            'Orphanet:999902' => $this->orphanet(
                'SYNTHETIC TEST direct ambiguity',
                ['MONDO:9999001', 'MONDO:9999002'],
                ['999904']
            ),

            // F11/F12: two OMIM references produce three MONDO candidates;
            // OMIM:999904 deliberately has no record of its own.
            'MONDO:9999003' => $this->mondo(
                'SYNTHETIC TEST candidate 3',
                ['999904']
            ),
            'Orphanet:999903' => $this->orphanet(
                'SYNTHETIC TEST bridge ambiguity',
                [],
                ['999901', '999904']
            ),

            // F13: the replacement is deliberately absent from the database.
            'MONDO:9999004' => $this->mondo(
                'SYNTHETIC TEST deprecated term with absent successor',
                [],
                Disease::STATUS_DEPRECATED,
                'SYNTHETIC TEST deprecated term with absent successor',
                'MONDO:9999005'
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function mondo(
        string $name,
        array $omimIds,
        int $status = Disease::STATUS_ACTIVE,
        ?string $deprecatedName = null,
        ?string $replacedBy = null
    ): array {
        return [
            'type' => Disease::TYPE_MONDO,
            'name' => $name,
            'deprecated_name' => $deprecatedName,
            'description' => null,
            'synonyms' => null,
            'xrefs' => [
                'omim_id' => $omimIds,
                'orpha_id' => [],
                'replaced_by' => $replacedBy,
            ],
            'status' => $status,
        ];
    }

    /** @return array<string, mixed> */
    private function orphanet(string $name, array $mondoIds, array $omimIds): array
    {
        return [
            'type' => Disease::TYPE_ORPHANET,
            'name' => $name,
            'deprecated_name' => null,
            'description' => null,
            'synonyms' => null,
            'xrefs' => [
                'mondo_id' => $mondoIds,
                'omim_id' => $omimIds,
            ],
            'status' => Disease::STATUS_ACTIVE,
        ];
    }
}
