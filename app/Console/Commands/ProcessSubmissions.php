<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Models\Job;
use App\Models\Submission;

class ProcessSubmissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process:submissions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all pending submissions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Get all submitted jobs (using V2 status)
        $jobs = Job::where('status', Job::STATUS_SUBMITTED)->get();

        if ($jobs->isEmpty()) {
            $this->info('No jobs to process.');
            return 0;
        }

        $this->info('Processing ' . $jobs->count() . ' submitted job(s)...');

        foreach ($jobs as $job)
        {
            $this->info('Processing job: ' . $job->slug);

            // Process all submissions in this job
            $submissions = $job->submissions;
            $this->info('  Found ' . $submissions->count() . ' submission(s)');

            foreach ($submissions as $submission)
            {
                // Transition submission based on current state
                if ($submission->status === Submission::STATUS_SUBMITTED_NEW) {
                    // New submission -> published
                    $submission->status = Submission::STATUS_PUBLISHED;
                    $submission->published_at = now();
                } elseif ($submission->status === Submission::STATUS_SUBMITTED_REPUBLISH) {
                    // Republish -> published
                    $submission->status = Submission::STATUS_PUBLISHED;
                    $submission->published_at = now();
                } elseif ($submission->status === Submission::STATUS_SUBMITTED_UNPUBLISH) {
                    // Unpublish -> unpublished
                    $submission->status = Submission::STATUS_UNPUBLISHED;
                    $submission->published_at = null;
                }
                $submission->save();
            }

            // Mark job as processed
            $job->status = Job::STATUS_PROCESSED;
            $job->save();

            $this->info('  Job ' . $job->slug . ' processed successfully');
        }

        $this->info('All jobs processed successfully!');
        return 0;
    }
}
