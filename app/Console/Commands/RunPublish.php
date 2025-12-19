<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;

use App\Models\Job;
use App\Models\Action;
use App\Models\Submission;

use App\Services\JobStateMachine;
use App\Services\SubmissionStateMachine;

use App\Http\Controllers\PublishController as PublishController;
use App\Events\PublishStatusUpdate;

class RunPublish extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'run:publish {arg=process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {

        $arg = $this->argument('arg');

        switch ($arg)
        {
            case 'test_init':
                $this->test($arg);
                break;
            case 'process':
                $this->process_actions();
                $this->process_jobs();
                break;
            case 'sgc_ids':
                $this->process_sgc_ids();
                break;
            default:
                print("INVALID ARG");
        }
    }


    /**
     * Test the remote conntection.
     */
    protected function test($arg)
    {
        print("TESTING REMOTE HANDSHAKE");

        // init handshake with gencc-search
        $gencc_search = new PublishController();

        $response = $gencc_search->init(new Request);

        dd($response);
                
    }


    /**
     * Publish all pending jobs.
     *
     */
    protected function process_jobs()
    {
        // Get jobs ready to publish (submitted status)
        $jobs = Job::where('status', Job::STATUS_SUBMITTED)
                   ->with([
                       'submissions',
                       'submissions.gene',
                       'submissions.disease',
                       'submissions.originalDisease',
                       'submissions.inheritance',
                       'submissions.classification',
                       'submissions.mechanism',
                       'submissions.submitter'
                   ])
                   ->whereDoesntHave('actions', function($query) {
                       $query->where('type', Action::TYPE_UNPUBLISH)
                             ->where('status', Action::STATUS_PENDING);
                   })
                   ->get();

        $this->info("Found {$jobs->count()} submitted jobs to publish");

        if ($jobs->count() === 0) {
            $this->info("No jobs to publish");
            return 0;
        }

        // init handshake with gencc-search
        $gencc_search = new PublishController();

        $this->info("Initializing handshake with gencc-search...");
        $response = $gencc_search->init(new Request);

        if ($response === null) {
            $this->error("Init failed: Response is null");
            return 500;
        }

        if (!isset($response['status_code'])) {
            $this->error("Init failed: No status_code in response");
            \Log::error('Publish init response missing status_code', ['response' => $response]);
            return 500;
        }

        if ($response['status_code'] != 200) {
            $this->error("Init failed with status code: {$response['status_code']}");
            \Log::error('Publish init failed', ['response' => $response]);
            return $response['status_code'];
        }

        $this->info("Handshake successful");

        foreach($jobs as $job)
        {
            // do NOT reprocess processed jobs
            if ($job->status == Job::STATUS_PROCESSED) {
                $this->warn("Skipping job {$job->slug} - already processed");
                continue;
            }

            $this->info("Publishing job {$job->slug} (status: {$job->status}) with {$job->submissions->count()} submissions");

            // Set is_publishing flag and broadcast start event
            $job->update(['is_publishing' => true]);
            PublishStatusUpdate::dispatch($job->slug, true, 'started');

            try {
                // Format and push submissions to gencc
                $response = $gencc_search->send_job(new Request, $job);

            // Convert JsonResponse to array if needed
            if ($response instanceof \Illuminate\Http\JsonResponse) {
                $responseData = $response->getData(true);
                $statusCode = $responseData['status_code'] ?? 'unknown';
                $this->info("Job {$job->slug} publish result: {$statusCode}");

                // Report any failures
                if (isset($responseData['failed_count']) && $responseData['failed_count'] > 0) {
                    $this->warn("  {$responseData['failed_count']} submission(s) failed and moved to draft job");
                }
            } elseif (isset($response['status_code'])) {
                $this->info("Job {$job->slug} publish result: {$response['status_code']}");

                if (isset($response['failed_count']) && $response['failed_count'] > 0) {
                    $this->warn("  {$response['failed_count']} submission(s) failed and moved to draft job");
                }
            }

                // Clear is_publishing flag and broadcast completion event
                $job->update(['is_publishing' => false]);
                PublishStatusUpdate::dispatch($job->slug, false, 'completed');

            } catch (\Exception $e) {
                // Clear is_publishing flag and broadcast failure event on error
                $job->update(['is_publishing' => false]);
                PublishStatusUpdate::dispatch($job->slug, false, 'failed');

                $this->error("Failed to publish job {$job->slug}: " . $e->getMessage());
                \Log::error("RunPublish: Failed to publish job {$job->slug}", [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // Continue to next job instead of stopping the entire process
            }
        }

        // close out the session to gencc
        $this->info("Closing session with gencc-search...");
        $response = $gencc_search->end(new Request);

        if (isset($response['status_code'])) {
            $this->info("Session closed with status: {$response['status_code']}");
            return $response['status_code'];
        }

        $this->error("End failed: No status_code in response");
        return 500;
    }


    /**
     * Publish all pending actions.
     * 
     */
    protected function process_actions()
    {
        $actions = Action::status(Action::STATUS_PENDING)->get();
        
        // init handshake with gencc-search
        $gencc_search = new PublishController();

        $response = $gencc_search->init(new Request);

        if ($response === null || $response['status_code'] != 200)
            return $response['status_code'] ?? 500;


        foreach ($actions as $action)
        {
            switch ($action->type)
            {
                case Action::TYPE_UNPUBLISH:
                    // Format and push action to gencc
                    $response = $gencc_search->send_action(new Request, $action);

                    //dd($response);
                    break;
                default:
                    break;
            }
        }

        // close out the session to gencc
        $response = $gencc_search->end(new Request);

        return ($response['status_code']);
    }

    /**
     * Publish all sgc_ids.
     *
     */
    protected function process_sgc_ids()
    {
        $submissions = Submission::whereNotNull('submission_data->search_row_id')->get();

        $totalSubmissions = $submissions->count();
        $processedCount = 0;

        $this->info("Processing {$totalSubmissions} SGC IDs...");

        // init handshake with gencc-search
        $gencc_search = new PublishController();

        $response = $gencc_search->init(new Request);

        if ($response === null || $response['status_code'] != 200)
            return $response['status_code'] ?? 500;


        foreach ($submissions as $submission)
        {
            $response = $gencc_search->send_sgc_id(new Request, $submission);

            $processedCount++;

            // Update progress every 100 records or on the last record
            if ($processedCount % 100 == 0 || $processedCount == $totalSubmissions) {
                $this->getOutput()->write("\r  Progress: {$processedCount}/{$totalSubmissions} SGC IDs updated");
            }
        }

        // Add newline after progress complete
        $this->getOutput()->write("\n");

        // close out the session to gencc
        $response = $gencc_search->end(new Request);

        return ($response['status_code']);
    }
}
