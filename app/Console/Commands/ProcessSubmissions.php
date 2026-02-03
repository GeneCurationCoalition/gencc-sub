<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use App\Models\Job;
use App\Models\Submission;
use App\Services\JobStateMachine;
use App\Services\SubmissionStateMachine;

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
                // Determine target state based on current submission status
                $targetState = match($submission->status) {
                    Submission::STATUS_NEW => Submission::STATUS_PUBLISHED,
                    Submission::STATUS_REPUBLISH => Submission::STATUS_PUBLISHED,
                    Submission::STATUS_UNPUBLISH => Submission::STATUS_UNPUBLISHED,
                    default => null
                };

                if ($targetState) {
                    SubmissionStateMachine::transition($submission, $targetState);

                    if ($targetState === Submission::STATUS_PUBLISHED) {
                        $submission->released_at = now();
                    } elseif ($targetState === Submission::STATUS_UNPUBLISHED) {
                        $submission->released_at = null;
                    }

                    $submission->save();
                }
            }

            // Mark job as released via state machine
            JobStateMachine::complete($job);
            $job->save();

            $this->info('  Job ' . $job->slug . ' processed successfully');
        }

        $this->info('All jobs processed successfully!');
        return 0;
    }
}
