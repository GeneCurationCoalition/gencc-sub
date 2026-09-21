<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Remove legacy disease mappings and make update:diseases re-read MONDO and
 * Orphanet in full under the exact-only mapping policy.
 *
 * Disease `xrefs` now hold exact-only equivalences, under the same `omim_id` /
 * `orpha_id` names the earlier importer filled with non-exact identifiers too,
 * and DiseaseResolver trusts what is stored there.  update:diseases skips any
 * source whose file headers match the ones it last recorded, so the existing
 * rows would otherwise keep the earlier values until upstream next published.
 * Clear the affected rows first so a removed upstream term cannot retain a
 * pre-policy broad mapping. Forgetting the headers then makes the next run
 * treat both sources as changed and repopulate current exact mappings. OMIM
 * rows' `xrefs` did not change shape, so their data and headers are kept.
 *
 * The import itself is left to update:diseases, which the deploy invokes after
 * migrations and the nightly timer invokes thereafter, so this migration needs
 * no network access.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Keep the rows and their stable primary keys: submissions reference
        // them. Only the mapping payload is invalid under the new policy.
        DB::table('diseases')
            ->where('type', 1) // Disease::TYPE_MONDO
            ->update([
                'xrefs' => json_encode([
                    'omim_id' => [],
                    'orpha_id' => [],
                    'replaced_by' => null,
                ]),
            ]);

        DB::table('diseases')
            ->where('type', 20) // Disease::TYPE_ORPHANET
            ->update([
                'xrefs' => json_encode([
                    'mondo_id' => [],
                    'omim_id' => [],
                ]),
            ]);

        DB::table('static_file_headers')
            ->whereIn('file_identifier', ['mondo_with_equivalents', 'orphanet_product1'])
            ->delete();
    }

    public function down(): void
    {
        // Nothing to restore: legacy mappings were not exactness-safe, and the
        // next import records current mappings and headers again.
    }
};
