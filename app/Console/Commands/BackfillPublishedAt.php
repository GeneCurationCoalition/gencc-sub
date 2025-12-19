<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Job;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;

class BackfillPublishedAt extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backfill:published-at';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill published_at timestamps for existing published jobs and submissions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting backfill of published_at timestamps...');

        // Backfill Jobs
        $this->info('Processing Jobs...');

        // Only backfill jobs that don't already have published_at set
        // Jobs with STATUS_PROCESSED have been through the publish workflow
        $completedJobs = Job::where('status', Job::STATUS_PROCESSED)
                            ->whereNull('published_at')
                            ->get();

        $jobCount = 0;
        foreach ($completedJobs as $job) {
            // Set published_at from updated_at to ensure historical accuracy
            $job->published_at = $job->updated_at;
            $job->save(['timestamps' => false]); // Don't update updated_at
            $jobCount++;
        }

        $this->info("Updated {$jobCount} jobs with published_at timestamps (skipped jobs that already had published_at set)");

        // Backfill Submissions
        $this->info('Processing Submissions...');

        // Use raw database update to preserve timestamps without triggering events
        // Only update submissions that don't already have published_at set
        $submissionCount = DB::update(
            'UPDATE submissions SET published_at = updated_at WHERE status = ? AND published_at IS NULL',
            [Submission::STATUS_PUBLISHED]
        );

        $this->info("Updated {$submissionCount} submissions with published_at timestamps (skipped submissions that already had published_at set)");
        $this->info('Backfill complete!');

        return Command::SUCCESS;
    }
}
