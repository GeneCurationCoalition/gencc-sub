<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clear legacy MONDO and Orphanet mappings before the exact-only importer runs.
 *
 * The old xrefs included non-exact mappings under the same keys the resolver
 * now trusts. Clear them once so terms absent from the next release cannot
 * retain those legacy mappings. Keep stable disease IDs and submission links.
 * OMIM rows do not contain the affected cross-ontology mappings.
 *
 * DiseaseOntologySources uses versioned file identifiers, so the next
 * update:diseases re-reads all sources without deleting historical headers.
 * The deploy runs that command after migrations; cross-ontology lookups are
 * unavailable until the mappings have been rebuilt. This migration uses no
 * network access. Future imports retain the last known exact-only mappings
 * when a term disappears, allowing deprecated terms to remain eligible.
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
    }

    public function down(): void
    {
        // Nothing to restore: legacy mappings were not exactness-safe, and the
        // next import records current mappings and headers again.
    }
};
