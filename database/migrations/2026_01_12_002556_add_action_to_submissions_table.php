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
     * Add `action` column to submissions table for audit trail.
     * The action represents the original intent of the submission:
     * - 'new': First submission for this SGC ID (v1)
     * - 'republish': Update to an existing submission (v2+)
     * - 'unpublish': Hide an existing submission (v2+)
     *
     * This field is retained after release for audit purposes.
     */
    public function up(): void
    {
        // Add column if it doesn't exist
        if (!Schema::hasColumn('submissions', 'action')) {
            Schema::table('submissions', function (Blueprint $table) {
                $table->string('action', 20)->nullable()->after('status');
                $table->index('action');
            });
        }

        // Backfill action from current status values
        // For pending submissions, action matches the status suffix
        DB::table('submissions')
            ->whereIn('status', ['draft_new', 'submitted_new'])
            ->update(['action' => 'new']);

        DB::table('submissions')
            ->whereIn('status', ['draft_republish', 'submitted_republish'])
            ->update(['action' => 'republish']);

        DB::table('submissions')
            ->whereIn('status', ['draft_unpublish', 'submitted_unpublish'])
            ->update(['action' => 'unpublish']);

        // For released submissions, derive from version_number and status
        // v1 published = action was 'new'
        DB::table('submissions')
            ->where('status', 'published')
            ->where('version_number', 1)
            ->update(['action' => 'new']);

        // v2+ published = action was 'republish'
        DB::table('submissions')
            ->where('status', 'published')
            ->where('version_number', '>', 1)
            ->update(['action' => 'republish']);

        // unpublished = action was 'unpublish'
        DB::table('submissions')
            ->where('status', 'unpublished')
            ->update(['action' => 'unpublish']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropIndex(['action']);
            $table->dropColumn('action');
        });
    }
};
