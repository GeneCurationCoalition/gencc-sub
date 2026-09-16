<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Re-import diseases so their `xrefs` hold exact-only equivalences
 * (docs/DISEASE_MAPPING.md).
 *
 * The earlier importer wrote `omim_id` / `orpha_id` under the same names but
 * with non-exact identifiers mixed in, and DiseaseResolver trusts whatever is
 * stored there.  A plain update:diseases run does not rewrite them, because it
 * skips any source whose file headers are unchanged, hence --force.
 *
 * The earlier shape is recognised by a key the current importer writes on every
 * row of the type, even when empty, and the earlier one never wrote.  A handful
 * of rows whose terms have left their source are never rewritten, so the test
 * is whether *any* row of the type carries the key, not whether all do.
 *
 * A database with no disease rows yet needs nothing: its first import writes the
 * current shape.  Once this migration has succeeded it never runs again; if the
 * import fails, the migration fails and is retried by the next migrate.
 */
return new class extends Migration
{
    private const SHAPE_MARKER = [
        1 => 'replaced_by', // Disease::TYPE_MONDO
        20 => 'mondo_id',   // Disease::TYPE_ORPHANET
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
        foreach (self::SHAPE_MARKER as $type => $key) {
            $rows = DB::table('diseases')->where('type', $type);

            if ((clone $rows)->exists() && ! $rows->whereJsonContainsKey("xrefs->{$key}")->exists()) {
                return true;
            }
        }

        return false;
    }
};
