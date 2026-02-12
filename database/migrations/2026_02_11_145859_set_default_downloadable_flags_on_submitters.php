<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Set downloadable = TRUE for all submitters EXCEPT:
     * - Submitters with allow_submissions = FALSE
     * - OMIM (GENCC:000109)
     */
    public function up(): void
    {
        // Set downloadable = TRUE for submitters that:
        // 1. Have allow_submissions = TRUE
        // 2. Are NOT OMIM (curie != 'GENCC:000109')
        DB::table('submitters')
            ->where('allow_submissions', true)
            ->where('curie', '!=', 'GENCC:000109')
            ->update(['downloadable' => true]);

        // Ensure OMIM and non-submitting submitters have downloadable = FALSE
        DB::table('submitters')
            ->where(function ($query) {
                $query->where('allow_submissions', false)
                      ->orWhere('curie', 'GENCC:000109');
            })
            ->update(['downloadable' => false]);
    }

    /**
     * Reverse the migrations.
     *
     * Reset all downloadable flags to FALSE (original state for most submitters)
     */
    public function down(): void
    {
        // Reset all to FALSE except Ambry and ClinGen (which were originally TRUE)
        DB::table('submitters')
            ->whereNotIn('curie', ['GENCC:000101', 'GENCC:000102'])
            ->update(['downloadable' => false]);
    }
};
