<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Removes the deprecated submission_date column from jobs and submissions tables.
     * This field has been replaced by:
     * - created_at: When the record was created (auto-managed by Laravel)
     * - submitted_at: When a job was submitted (jobs table only)
     * - released_at: When a submission was released (published or unpublished)
     *
     * Before removing, we migrate the data:
     * - submission_date -> created_at (if created_at is null)
     * - For jobs: submission_date is also used for submitted_at (handled in previous migration)
     */
    public function up(): void
    {
        // Only run data migration and drop if submission_date columns exist
        // (won't exist on fresh migrations/tests)
        if (Schema::hasColumn('jobs', 'submission_date')) {
            // First, copy submission_date to created_at where created_at is null
            // This handles legacy records that might not have created_at set
            DB::statement("
                UPDATE jobs
                SET created_at = submission_date
                WHERE created_at IS NULL
                AND submission_date IS NOT NULL
            ");

            // For jobs, also ensure released_at/published_at is set for processed jobs
            // (use submission_date as fallback if released_at/published_at is null)
            $releaseColumn = Schema::hasColumn('jobs', 'released_at') ? 'released_at' : 'published_at';
            DB::statement("
                UPDATE jobs
                SET {$releaseColumn} = submission_date
                WHERE status = 'processed'
                AND {$releaseColumn} IS NULL
                AND submission_date IS NOT NULL
            ");

            // Now drop the submission_date column
            Schema::table('jobs', function (Blueprint $table) {
                // Drop the index first before dropping the column
                $table->dropIndex(['submission_date']);
                $table->dropColumn('submission_date');
            });
        }

        if (Schema::hasColumn('submissions', 'submission_date')) {
            DB::statement("
                UPDATE submissions
                SET created_at = submission_date
                WHERE created_at IS NULL
                AND submission_date IS NOT NULL
            ");

            Schema::table('submissions', function (Blueprint $table) {
                $table->dropColumn('submission_date');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->timestamp('submission_date')->nullable()->after('submitter_id');
            $table->index('submission_date');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->timestamp('submission_date')->nullable()->after('submitter_id');
        });

        // Restore submission_date from created_at
        DB::statement("
            UPDATE jobs
            SET submission_date = created_at
        ");

        DB::statement("
            UPDATE submissions
            SET submission_date = created_at
        ");
    }
};
