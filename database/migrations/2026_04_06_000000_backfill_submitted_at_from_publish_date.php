<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill submitted_at from publish_date for historical imports.
     *
     * Historical submissions (imported via ImportGencc) have publish_date set
     * from the CSV's submitted_run_date but no submitted_at value.
     * This migration populates submitted_at so the search UI can use a single
     * consistent column for the "Submitted" date.
     */
    public function up(): void
    {
        DB::statement("
            UPDATE submissions
            SET submitted_at = publish_date
            WHERE submitted_at IS NULL
            AND publish_date IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only null out submitted_at for records that were backfilled
        // (where submitted_at equals publish_date, indicating they came from this migration)
        DB::statement("
            UPDATE submissions
            SET submitted_at = NULL
            WHERE submitted_at = publish_date
        ");
    }
};
