<?php

namespace Tests\Unit;

use App\Models\Disease;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The migration that forces a disease re-import when the table still holds
 * xrefs written before the exact-only policy.
 */
class ReimportDiseasesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        (require database_path('migrations/2026_09_15_120000_reimport_diseases_as_exact_only_xrefs.php'))->up();
    }

    /** @test */
    public function an_empty_table_needs_no_import(): void
    {
        Artisan::shouldReceive('call')->never();

        $this->runMigration();
    }

    /** @test */
    public function rows_already_in_the_exact_only_shape_need_no_import(): void
    {
        Disease::factory()->mondo()->withXrefs(['exact_omim' => [], 'exact_orphanet' => [], 'replaced_by' => null])->create();
        Disease::factory()->orphanet()->withXrefs(['exact_mondo' => [], 'exact_omim' => []])->create();

        // A MONDO term absent from later releases keeps its old keys forever
        Disease::factory()->mondo()->deprecated()->withXrefs(['omim_id' => [], 'orpha_id' => null])->create();

        Artisan::shouldReceive('call')->never();

        $this->runMigration();
    }

    /** @test */
    public function pre_policy_rows_force_an_import(): void
    {
        Disease::factory()->mondo()->withXrefs(['omim_id' => ['600001'], 'orpha_id' => '700001'])->create();

        Artisan::shouldReceive('call')
            ->once()
            ->withArgs(fn ($command, $parameters) => $command === 'update:diseases' && $parameters === ['--force' => true])
            ->andReturnUsing(function () {
                DB::table('diseases')->update(['xrefs' => json_encode(['exact_omim' => ['600001'], 'exact_orphanet' => ['700001']])]);

                return 0;
            });

        $this->runMigration();
    }

    /** @test */
    public function an_import_that_leaves_pre_policy_rows_fails_the_migration(): void
    {
        Disease::factory()->orphanet()->withXrefs(['omim_id' => [], 'mondo_id' => null])->create();

        Artisan::shouldReceive('call')->once()->andReturn(0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('update:diseases --force');

        $this->runMigration();
    }
}
