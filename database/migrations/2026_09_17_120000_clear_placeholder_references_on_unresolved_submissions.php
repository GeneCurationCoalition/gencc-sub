<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clear the stand-in records previously stored for reference fields that did
 * not resolve.
 *
 * Submission::load_from_json() used to store a real record in place of an
 * unresolved gene (the '-' gene), disease (MONDO:0000001, in both disease
 * columns) or mode of inheritance (HP:0000005), so the portal displayed values
 * the submission never had.  It now leaves those columns null, as it already
 * did for classification.  A column is cleared only when the submission
 * records that field's error: HP:0000005 is also a real "Unknown" answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        $placeholders = [
            'gene_hgnc_id' => ['gene_id' => DB::table('genes')->where('symbol', '-')->value('id')],
            'disease_curie_id' => array_fill_keys(
                ['disease_id', 'original_disease_id'],
                DB::table('diseases')->where('curie', 'MONDO:0000001')->value('id')
            ),
            'moi_curie_id' => ['inheritance_id' => DB::table('inheritances')->where('curie', 'HP:0000005')->value('id')],
        ];

        foreach ($placeholders as $errorKey => $columns) {
            foreach ($columns as $column => $placeholderId) {
                if ($placeholderId === null) {
                    continue;
                }

                DB::table('submissions')
                    ->where($column, $placeholderId)
                    ->whereJsonContainsKey("submission_errors->{$errorKey}")
                    ->update([$column => null]);
            }
        }
    }

    public function down(): void
    {
        // The cleared values were stand-ins, not data
    }
};
