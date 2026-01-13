<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Submission;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add is_live column to track which submission version is the current released state.
     *
     * This is separate from is_most_recent which tracks version ordering:
     * - is_most_recent: true = highest version number for this SID (may be a draft)
     * - is_live: true = current released state (published OR unpublished)
     *
     * Key concept: Unpublished submissions ARE live because they represent the current
     * public state of that SGC ID (hidden from view, but that hiding IS the state).
     *
     * Examples:
     * - Published V1, no pending changes: is_most_recent=true, is_live=true
     * - Published V1 with draft_republish V2 pending: V1 has is_most_recent=false, is_live=true
     *                                                  V2 has is_most_recent=true, is_live=false
     * - After V2 is released: V1 has is_most_recent=false, is_live=false (archived)
     *                         V2 has is_most_recent=true, is_live=true
     * - Unpublished V1 (current state): is_most_recent=true, is_live=true (hidden but live)
     * - Unpublished V1 with republish V2 pending: V1 has is_most_recent=false, is_live=true
     *                                              V2 has is_most_recent=true, is_live=false
     */
    public function up(): void
    {
        // Add column if it doesn't exist (handles partial migration failure)
        if (!Schema::hasColumn('submissions', 'is_live')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->boolean('is_live')->default(false)->after('is_most_recent');
                $table->index('is_live');
            });
        }

        // Set is_live=true for all released submissions (published OR unpublished) that are the current released version
        // A submission is live if:
        // 1. It's in 'published' or 'unpublished' status (both are released states), AND
        // 2. There is no newer released version (published or unpublished) with the same SID
        //
        // Note: Unpublished submissions ARE live because they represent the current public state
        // of that SGC ID - the fact that it's "hidden" IS the current state.
        //
        // Use database-agnostic approach for compatibility with SQLite (tests) and MySQL (production)
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            // MySQL: Use LEFT JOIN since subqueries referencing same table aren't allowed in UPDATE
            DB::statement("
                UPDATE submissions s
                LEFT JOIN submissions s2 ON s2.sid = s.sid
                    AND s2.version_number > s.version_number
                    AND s2.status IN ('published', 'unpublished')
                    AND s2.deleted_at IS NULL
                SET s.is_live = 1
                WHERE s.status IN ('published', 'unpublished')
                AND s.deleted_at IS NULL
                AND s2.id IS NULL
            ");
        } else {
            // SQLite and other databases: Use NOT EXISTS subquery
            DB::statement("
                UPDATE submissions
                SET is_live = 1
                WHERE status IN ('published', 'unpublished')
                AND deleted_at IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM submissions s2
                    WHERE s2.sid = submissions.sid
                    AND s2.version_number > submissions.version_number
                    AND s2.status IN ('published', 'unpublished')
                    AND s2.deleted_at IS NULL
                )
            ");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['is_live']);
            $table->dropColumn('is_live');
        });
    }
};
