<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make the next update:diseases run re-read MONDO and Orphanet in full
 * (docs/DISEASE_MAPPING.md).
 *
 * Disease `xrefs` now hold exact-only equivalences, under the same `omim_id` /
 * `orpha_id` names the earlier importer filled with non-exact identifiers too,
 * and DiseaseResolver trusts what is stored there.  update:diseases skips any
 * source whose file headers match the ones it last recorded, so the existing
 * rows would otherwise keep the earlier values until upstream next published.
 * Forgetting those headers makes the next run treat both sources as changed.
 * OMIM rows' `xrefs` did not change shape, so its headers are kept.
 *
 * The import itself is left to update:diseases (the nightly timer, which the
 * deploy also starts once), so this migration needs no network access.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('static_file_headers')
            ->whereIn('file_identifier', ['mondo_with_equivalents', 'orphanet_product1'])
            ->delete();
    }

    public function down(): void
    {
        // Nothing to restore: the next import records current headers again
    }
};
