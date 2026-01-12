<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add submitted_at after published_at (or released_at if it exists after rename)
        $afterColumn = Schema::hasColumn('jobs', 'released_at') ? 'released_at' : 'published_at';
        Schema::table('jobs', function (Blueprint $table) use ($afterColumn) {
            $table->timestamp('submitted_at')->nullable()->after($afterColumn);
        });

        // Populate submitted_at for existing jobs based on status:
        // - For 'submitted' jobs: use submission_date as submitted_at
        // - For 'processed' jobs: use submission_date as submitted_at (they were submitted before being processed)
        // Only run if submission_date column exists (won't exist on fresh migrations/tests)
        if (Schema::hasColumn('jobs', 'submission_date')) {
            DB::statement("
                UPDATE jobs
                SET submitted_at = submission_date
                WHERE status IN ('submitted', 'processed')
                AND submission_date IS NOT NULL
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('jobs', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
    }
};
