<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove jobs that have zero submissions.
     */
    public function up(): void
    {
        $jobIds = DB::table('jobs')
            ->leftJoin('submissions', 'jobs.id', '=', 'submissions.job_id')
            ->whereNull('submissions.id')
            ->pluck('jobs.id');

        if ($jobIds->isEmpty()) {
            return;
        }

        // Clean up any related records first
        DB::table('documents')->whereIn('job_id', $jobIds)->delete();
        DB::table('actions')->whereIn('job_id', $jobIds)->delete();
        DB::table('jobs')->whereIn('id', $jobIds)->delete();
    }

    /**
     * This migration is not reversible — the jobs had no data.
     */
    public function down(): void
    {
        // Jobs with no submissions cannot be restored
    }
};
