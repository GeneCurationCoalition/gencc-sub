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
     * Simplifies submission status values from 8 to 5:
     *
     * OLD (8 values):
     * - draft_new, submitted_new -> new
     * - draft_republish, submitted_republish -> republish
     * - draft_unpublish, submitted_unpublish -> unpublish
     * - published -> published (unchanged)
     * - unpublished -> unpublished (unchanged)
     *
     * NEW (5 values):
     * - new: First submission for this SGC ID (v1)
     * - republish: Update to existing submission (v2+)
     * - unpublish: Hide existing submission (v2+)
     * - published: Released and visible
     * - unpublished: Released but hidden
     *
     * The stage (draft/submitted) is now derived from Job.status instead
     * of being stored in the submission status.
     */
    public function up(): void
    {
        // Update pending submissions: remove draft_/submitted_ prefix
        DB::table('submissions')
            ->whereIn('status', ['draft_new', 'submitted_new'])
            ->update(['status' => 'new']);

        DB::table('submissions')
            ->whereIn('status', ['draft_republish', 'submitted_republish'])
            ->update(['status' => 'republish']);

        DB::table('submissions')
            ->whereIn('status', ['draft_unpublish', 'submitted_unpublish'])
            ->update(['status' => 'unpublish']);

        // Note: 'published' and 'unpublished' status values remain unchanged
    }

    /**
     * Reverse the migrations.
     *
     * To rollback, we need to restore the compound statuses based on Job.status.
     * - If job is 'draft', prefix with 'draft_'
     * - If job is 'submitted', prefix with 'submitted_'
     * - If job is 'released', leave as 'published' or 'unpublished'
     */
    public function down(): void
    {
        // Get all pending submissions and restore their compound status
        $pendingSubmissions = DB::table('submissions')
            ->whereIn('status', ['new', 'republish', 'unpublish'])
            ->join('jobs', 'submissions.job_id', '=', 'jobs.id')
            ->select('submissions.id', 'submissions.status', 'jobs.status as job_status')
            ->get();

        foreach ($pendingSubmissions as $submission) {
            $prefix = $submission->job_status === 'submitted' ? 'submitted_' : 'draft_';
            $newStatus = $prefix . $submission->status;

            DB::table('submissions')
                ->where('id', $submission->id)
                ->update(['status' => $newStatus]);
        }
    }
};
