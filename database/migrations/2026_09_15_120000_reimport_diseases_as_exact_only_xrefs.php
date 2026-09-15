<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Re-import diseases into the exact-only `xrefs` shape (docs/DISEASE_MAPPING.md).
 *
 * DiseaseResolver reads only the `exact_*` keys, so until every source has been
 * re-read no OMIM or Orphanet identifier resolves.  A plain update:diseases run
 * does not fix that, because it skips any source whose file headers are
 * unchanged, hence --force.
 *
 * A database with no disease rows yet needs nothing: its first import writes the
 * new shape.  Once this migration has succeeded it never runs again; if the
 * import fails, the migration fails and is retried by the next migrate.
 */
return new class extends Migration
{
    /**
     * The key every re-imported row of each type carries, even when empty.
     */
    private const EXACT_KEY_BY_TYPE = [
        1 => 'exact_omim',   // Disease::TYPE_MONDO
        20 => 'exact_mondo', // Disease::TYPE_ORPHANET
    ];

    public function up(): void
    {
        if (! $this->predatesExactOnlyXrefs()) {
            return;
        }

        Artisan::call('update:diseases', ['--force' => true], new ConsoleOutput());

        if ($this->predatesExactOnlyXrefs()) {
            throw new RuntimeException(
                'Disease xrefs are still in the pre-exact-only shape after `php artisan update:diseases --force`. '
                .'Check its output for the failed source, then run the migrations again.'
            );
        }
    }

    public function down(): void
    {
        // The previous shape cannot be rebuilt without the previous importer
    }

    private function predatesExactOnlyXrefs(): bool
    {
        foreach (self::EXACT_KEY_BY_TYPE as $type => $key) {
            $rows = DB::table('diseases')->where('type', $type);

            if ((clone $rows)->exists() && ! $rows->whereNotNull("xrefs->{$key}")->exists()) {
                return true;
            }
        }

        return false;
    }
};
