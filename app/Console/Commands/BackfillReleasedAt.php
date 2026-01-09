<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Job;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;

class BackfillReleasedAt extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backfill:released-at';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill released_at timestamps for existing published jobs and submissions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting backfill of released_at timestamps...');

        // Backfill Jobs
        $this->info('Processing Jobs...');

        // Only backfill jobs that don't already have released_at set
        // Jobs with STATUS_PROCESSED have been through the publish workflow
        $completedJobs = Job::where('status', Job::STATUS_PROCESSED)
                            ->whereNull('released_at')
                            ->get();

        $jobCount = 0;
        foreach ($completedJobs as $job) {
            // Set released_at from updated_at to ensure historical accuracy
            $job->released_at = $job->updated_at;
            $job->save(['timestamps' => false]); // Don't update updated_at
            $jobCount++;
        }

        $this->info("Updated {$jobCount} jobs with released_at timestamps (skipped jobs that already had released_at set)");

        // Backfill Submissions
        $this->info('Processing Submissions...');

        // Use raw database update to preserve timestamps without triggering events
        // Only update submissions that don't already have released_at set
        $submissionCount = DB::update(
            'UPDATE submissions SET released_at = updated_at WHERE status = ? AND released_at IS NULL',
            [Submission::STATUS_PUBLISHED]
        );

        $this->info("Updated {$submissionCount} submissions with released_at timestamps (skipped submissions that already had released_at set)");
        $this->info('Backfill complete!');

        return Command::SUCCESS;
    }
}
