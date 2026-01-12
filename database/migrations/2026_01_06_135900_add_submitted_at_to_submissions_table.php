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
     * Adds submitted_at timestamp to track when a submission transitions
     * from draft_* to submitted_* status.
     */
    public function up(): void
    {
        // Add submitted_at after published_at (or released_at if it exists after rename)
        $afterColumn = Schema::hasColumn('submissions', 'released_at') ? 'released_at' : 'published_at';
        Schema::table('submissions', function (Blueprint $table) use ($afterColumn) {
            $table->timestamp('submitted_at')->nullable()->after($afterColumn);
        });

        // Populate submitted_at for existing submissions that are in submitted or terminal states
        // For submitted_* states: use submission_date as submitted_at
        // For published/unpublished: use submission_date as submitted_at (they were submitted before being processed)
        // Only run if submission_date column exists (won't exist on fresh migrations/tests)
        if (Schema::hasColumn('submissions', 'submission_date')) {
            DB::statement("
                UPDATE submissions
                SET submitted_at = submission_date
                WHERE status IN ('submitted_new', 'submitted_republish', 'submitted_unpublish', 'published', 'unpublished')
                AND submission_date IS NOT NULL
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
    }
};
