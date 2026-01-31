<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

use App\Models\Job;
use App\Models\Action;
use App\Models\Submission;

use App\Services\JobStateMachine;
use App\Services\SubmissionStateMachine;

use App\Http\Controllers\ReleaseController;
use App\Events\PublishStatusUpdate;

class GenccRelease extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gencc:release {arg=process}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release pending submissions to gencc-search';

    /**
     * Release statistics for tracking
     */
    protected $releaseStats = [
        'new' => 0,
        'republish' => 0,
        'unpublish' => 0,
        'failed' => 0,
        'by_submitter' => [],
        'jobs_processed' => [],
        'actions_processed' => [],
    ];

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
                // Check if there's anything to process before starting
                $pendingJobs = $this->getPendingJobs();
                $pendingActions = Action::status(Action::STATUS_PENDING)->get();

                if ($pendingJobs->count() === 0 && $pendingActions->count() === 0) {
                    $this->info("No jobs or actions to process. Skipping release.");
                    \Log::info('GenCC Release: No pending jobs or actions to process.');
                    return 0;
                }

                $this->process_actions();
                $this->process_jobs();
                $this->triggerUpdateCounts();
                $this->generateReleaseNotes();
                $this->generateSubmissionsCsv();
                break;
            case 'sgc_ids':
                $this->process_sgc_ids();
                break;
            default:
                print("INVALID ARG");
        }
    }

    /**
     * Get pending jobs ready for release.
     */
    protected function getPendingJobs()
    {
        return Job::where('status', Job::STATUS_SUBMITTED)
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
    }


    /**
     * Test the remote conntection.
     */
    protected function test($arg)
    {
        print("TESTING REMOTE HANDSHAKE");

        // init handshake with gencc-search
        $gencc_search = new ReleaseController();

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
        $jobs = $this->getPendingJobs();

        $this->info("Found {$jobs->count()} submitted jobs to publish");

        if ($jobs->count() === 0) {
            $this->info("No jobs to publish");
            return 0;
        }

        // init handshake with gencc-search
        $gencc_search = new ReleaseController();

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

            // Track statistics for this job before processing
            $this->trackJobStatistics($job);

            // Set is_publishing flag and broadcast start event
            $job->update(['is_publishing' => true]);
            PublishStatusUpdate::dispatch($job->slug, true, 'started');

            try {
                // Format and push submissions to gencc
                $response = $gencc_search->send_job(new Request, $job);

                $failedCount = 0;

                // Convert JsonResponse to array if needed
                if ($response instanceof \Illuminate\Http\JsonResponse) {
                    $responseData = $response->getData(true);
                    $statusCode = $responseData['status_code'] ?? 'unknown';
                    $this->info("Job {$job->slug} publish result: {$statusCode}");

                    // Report any failures
                    if (isset($responseData['failed_count']) && $responseData['failed_count'] > 0) {
                        $failedCount = $responseData['failed_count'];
                        $this->warn("  {$failedCount} submission(s) failed and moved to draft job");
                    }
                } elseif (isset($response['status_code'])) {
                    $this->info("Job {$job->slug} publish result: {$response['status_code']}");

                    if (isset($response['failed_count']) && $response['failed_count'] > 0) {
                        $failedCount = $response['failed_count'];
                        $this->warn("  {$failedCount} submission(s) failed and moved to draft job");
                    }
                }

                // Track failed submissions
                $this->releaseStats['failed'] += $failedCount;

                // Record the job as processed
                $this->releaseStats['jobs_processed'][] = [
                    'slug' => $job->slug,
                    'submitter' => $job->submitter->name ?? 'Unknown',
                    'submission_count' => $job->submissions->count(),
                    'failed_count' => $failedCount,
                ];

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

        if (!isset($response['status_code'])) {
            $this->error("End failed: No status_code in response");
            return 500;
        }

        $this->info("Session closed with status: {$response['status_code']}");

        return $response['status_code'];
    }


    /**
     * Publish all pending actions.
     *
     */
    protected function process_actions()
    {
        $actions = Action::status(Action::STATUS_PENDING)->with(['submission', 'submission.submitter', 'submission.gene', 'submission.disease'])->get();

        if ($actions->count() === 0) {
            return 0;
        }

        // init handshake with gencc-search
        $gencc_search = new ReleaseController();

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

                    // Track unpublish statistics
                    $this->releaseStats['unpublish']++;

                    // Track by submitter
                    $submitterName = $action->submission->submitter->name ?? 'Unknown';
                    if (!isset($this->releaseStats['by_submitter'][$submitterName])) {
                        $this->releaseStats['by_submitter'][$submitterName] = [
                            'new' => 0,
                            'republish' => 0,
                            'unpublish' => 0,
                        ];
                    }
                    $this->releaseStats['by_submitter'][$submitterName]['unpublish']++;

                    // Record the action
                    $this->releaseStats['actions_processed'][] = [
                        'type' => 'unpublish',
                        'submission_sid' => $action->submission->sid ?? 'Unknown',
                        'gene' => $action->submission->gene->symbol ?? 'Unknown',
                        'disease' => $action->submission->disease->name ?? 'Unknown',
                        'submitter' => $submitterName,
                    ];

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
        $gencc_search = new ReleaseController();

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

    /**
     * Trigger update_counts on gencc-search to refresh classification counts
     * Called after all publishing operations are complete
     */
    protected function triggerUpdateCounts()
    {
        $this->info("Triggering update counts on gencc-search...");

        $gencc_search = new ReleaseController();

        // Initialize session
        $initResponse = $gencc_search->init(new Request);

        if ($initResponse === null || ($initResponse['status_code'] ?? null) != 200) {
            $this->warn("Update counts: Failed to initialize session");
            return;
        }

        // Trigger update counts
        $countResponse = $gencc_search->updateCounts(new Request);

        if (isset($countResponse['status_code']) && $countResponse['status_code'] == 200) {
            $this->info("Update counts completed successfully");
        } else {
            $this->warn("Update counts failed: " . ($countResponse['error'] ?? 'Unknown error'));
        }

        // Close session
        $gencc_search->end(new Request);
    }

    /**
     * Track statistics for a job's submissions.
     */
    protected function trackJobStatistics(Job $job)
    {
        foreach ($job->submissions as $submission) {
            $submitterName = $submission->submitter->name ?? 'Unknown';

            // Initialize submitter stats if not exists
            if (!isset($this->releaseStats['by_submitter'][$submitterName])) {
                $this->releaseStats['by_submitter'][$submitterName] = [
                    'new' => 0,
                    'republish' => 0,
                    'unpublish' => 0,
                ];
            }

            // Track by submission status/action
            if ($submission->status === Submission::STATUS_NEW) {
                $this->releaseStats['new']++;
                $this->releaseStats['by_submitter'][$submitterName]['new']++;
            } elseif ($submission->status === Submission::STATUS_REPUBLISH) {
                $this->releaseStats['republish']++;
                $this->releaseStats['by_submitter'][$submitterName]['republish']++;
            } elseif ($submission->status === Submission::STATUS_UNPUBLISH) {
                $this->releaseStats['unpublish']++;
                $this->releaseStats['by_submitter'][$submitterName]['unpublish']++;
            }
        }
    }

    /**
     * Generate a release notes markdown file.
     */
    protected function generateReleaseNotes()
    {
        $releaseDate = Carbon::now();
        $filename = 'Release_Notes_' . $releaseDate->format('Y-m-d_His') . '.md';

        // Ensure the releases directory exists
        $releasesDir = storage_path('releases');
        if (!File::isDirectory($releasesDir)) {
            File::makeDirectory($releasesDir, 0755, true);
        }

        $totalSubmissions = $this->releaseStats['new'] + $this->releaseStats['republish'];
        $totalChanges = $totalSubmissions + $this->releaseStats['unpublish'];

        // Gather cumulative statistics for all released submissions
        $cumulativeStats = $this->gatherCumulativeStatistics();

        // Build the markdown content
        $content = "# GenCC Release Notes\n\n";
        $content .= "**Release Date:** {$releaseDate->format('F j, Y g:i A T')}\n\n";

        // Summary section
        $content .= "## Summary\n\n";
        $content .= "| Metric | Count |\n";
        $content .= "|--------|-------|\n";
        $content .= "| New Submissions | {$this->releaseStats['new']} |\n";
        $content .= "| Updated Submissions | {$this->releaseStats['republish']} |\n";
        $content .= "| Unpublished Submissions | {$this->releaseStats['unpublish']} |\n";
        $content .= "| Failed | {$this->releaseStats['failed']} |\n";
        $content .= "| **Total Changes** | **{$totalChanges}** |\n\n";

        // Jobs processed section
        if (!empty($this->releaseStats['jobs_processed'])) {
            $content .= "## Jobs Processed\n\n";
            $content .= "| Job | Submitter | Submissions | Failed |\n";
            $content .= "|-----|-----------|-------------|--------|\n";

            foreach ($this->releaseStats['jobs_processed'] as $job) {
                $content .= "| {$job['slug']} | {$job['submitter']} | {$job['submission_count']} | {$job['failed_count']} |\n";
            }
            $content .= "\n";
        }

        // Breakdown by submitter section
        if (!empty($this->releaseStats['by_submitter'])) {
            $content .= "## Breakdown by Submitter\n\n";
            $content .= "| Submitter | New | Updated | Unpublished | Total |\n";
            $content .= "|-----------|-----|---------|-------------|-------|\n";

            // Sort by total submissions descending
            $submitters = $this->releaseStats['by_submitter'];
            uasort($submitters, function ($a, $b) {
                $totalA = $a['new'] + $a['republish'] + $a['unpublish'];
                $totalB = $b['new'] + $b['republish'] + $b['unpublish'];
                return $totalB <=> $totalA;
            });

            foreach ($submitters as $name => $stats) {
                $total = $stats['new'] + $stats['republish'] + $stats['unpublish'];
                $content .= "| {$name} | {$stats['new']} | {$stats['republish']} | {$stats['unpublish']} | {$total} |\n";
            }
            $content .= "\n";
        }

        // Unpublished submissions detail section
        if (!empty($this->releaseStats['actions_processed'])) {
            $content .= "## Unpublished Submissions\n\n";
            $content .= "| Submission ID | Gene | Disease | Submitter |\n";
            $content .= "|---------------|------|---------|----------|\n";

            foreach ($this->releaseStats['actions_processed'] as $action) {
                if ($action['type'] === 'unpublish') {
                    $content .= "| {$action['submission_sid']} | {$action['gene']} | {$action['disease']} | {$action['submitter']} |\n";
                }
            }
            $content .= "\n";
        }

        // Current State section - full accounting of all released submissions
        $content .= "## Current State (All Released Submissions)\n\n";

        // Overall totals
        $content .= "### Overall Totals\n\n";
        $content .= "| Metric | Count |\n";
        $content .= "|--------|-------|\n";
        $content .= "| Total Live Submissions | {$cumulativeStats['total_live']} |\n";
        $content .= "| Total Published (including archived) | {$cumulativeStats['total_published']} |\n";
        $content .= "| Total Unpublished | {$cumulativeStats['total_unpublished']} |\n";
        $content .= "| Unique Gene-Disease Pairs | {$cumulativeStats['unique_gene_disease']} |\n";
        $content .= "| Unique Genes | {$cumulativeStats['unique_genes']} |\n";
        $content .= "| Unique Diseases | {$cumulativeStats['unique_diseases']} |\n\n";

        // Classification breakdown
        if (!empty($cumulativeStats['by_classification'])) {
            $content .= "### By Classification\n\n";
            $content .= "| Classification | Count |\n";
            $content .= "|----------------|-------|\n";

            foreach ($cumulativeStats['by_classification'] as $classification => $count) {
                $content .= "| {$classification} | {$count} |\n";
            }
            $content .= "\n";
        }

        // All submissions by submitter
        if (!empty($cumulativeStats['by_submitter'])) {
            $content .= "### All Submissions by Submitter\n\n";
            $content .= "| Submitter | Live | Published | Unpublished |\n";
            $content .= "|-----------|------|-----------|-------------|\n";

            foreach ($cumulativeStats['by_submitter'] as $submitter => $stats) {
                $content .= "| {$submitter} | {$stats['live']} | {$stats['published']} | {$stats['unpublished']} |\n";
            }
            $content .= "\n";
        }

        // Footer
        $content .= "---\n";
        $content .= "*Generated automatically by GenCC Release Process*\n";

        // Write the file
        $filepath = $releasesDir . '/' . $filename;
        File::put($filepath, $content);

        $this->info("Release notes generated: {$filepath}");
        \Log::info("GenCC Release: Release notes generated at {$filepath}", [
            'new' => $this->releaseStats['new'],
            'republish' => $this->releaseStats['republish'],
            'unpublish' => $this->releaseStats['unpublish'],
            'failed' => $this->releaseStats['failed'],
            'total_live' => $cumulativeStats['total_live'],
        ]);
    }

    /**
     * Gather cumulative statistics for all released submissions.
     */
    protected function gatherCumulativeStatistics(): array
    {
        $stats = [
            'total_live' => 0,
            'total_published' => 0,
            'total_unpublished' => 0,
            'unique_gene_disease' => 0,
            'unique_genes' => 0,
            'unique_diseases' => 0,
            'by_classification' => [],
            'by_submitter' => [],
        ];

        // Total live submissions (currently visible in gencc-search)
        $stats['total_live'] = Submission::where('is_live', true)->count();

        // Total published (including archived versions)
        $stats['total_published'] = Submission::where('status', Submission::STATUS_PUBLISHED)->count();

        // Total unpublished
        $stats['total_unpublished'] = Submission::where('status', Submission::STATUS_UNPUBLISHED)->count();

        // Unique gene-disease pairs (from live submissions only)
        $stats['unique_gene_disease'] = Submission::where('is_live', true)
            ->select('gene_id', 'disease_id')
            ->distinct()
            ->count();

        // Unique genes (from live submissions)
        $stats['unique_genes'] = Submission::where('is_live', true)
            ->distinct('gene_id')
            ->count('gene_id');

        // Unique diseases (from live submissions)
        $stats['unique_diseases'] = Submission::where('is_live', true)
            ->distinct('disease_id')
            ->count('disease_id');

        // By classification (live submissions only)
        $classificationCounts = Submission::where('is_live', true)
            ->join('classifications', 'submissions.classification_id', '=', 'classifications.id')
            ->select('classifications.name', DB::raw('count(*) as count'))
            ->groupBy('classifications.name')
            ->orderByDesc('count')
            ->get();

        foreach ($classificationCounts as $row) {
            $stats['by_classification'][$row->name] = $row->count;
        }

        // By submitter (all released submissions - live, published, unpublished)
        $submitterStats = Submission::whereIn('submissions.status', [Submission::STATUS_PUBLISHED, Submission::STATUS_UNPUBLISHED])
            ->join('submitters', 'submissions.submitter_id', '=', 'submitters.id')
            ->select(
                'submitters.name',
                DB::raw('SUM(CASE WHEN is_live = 1 THEN 1 ELSE 0 END) as live_count'),
                DB::raw('SUM(CASE WHEN submissions.status = "published" THEN 1 ELSE 0 END) as published_count'),
                DB::raw('SUM(CASE WHEN submissions.status = "unpublished" THEN 1 ELSE 0 END) as unpublished_count')
            )
            ->groupBy('submitters.name')
            ->orderByDesc('live_count')
            ->get();

        foreach ($submitterStats as $row) {
            $stats['by_submitter'][$row->name] = [
                'live' => $row->live_count,
                'published' => $row->published_count,
                'unpublished' => $row->unpublished_count,
            ];
        }

        return $stats;
    }

    /**
     * Generate a CSV file with all live submissions for download.
     */
    protected function generateSubmissionsCsv()
    {
        $this->info("Generating submissions CSV...");

        // Ensure the exports directory exists (in public storage for web access)
        $exportsDir = storage_path('app/public/exports');
        if (!File::isDirectory($exportsDir)) {
            File::makeDirectory($exportsDir, 0755, true);
        }

        // Generate timestamped filename and a "latest" symlink
        $releaseDate = Carbon::now();
        $timestampedFilename = 'gencc-submissions-' . $releaseDate->format('Y-m-d') . '.csv';
        $latestFilename = 'gencc-submissions.csv';

        // Get all live submissions with relationships
        $submissions = Submission::where('is_live', true)
            ->with([
                'gene',
                'disease',
                'originalDisease',
                'classification',
                'inheritance',
                'mechanism',
                'submitter',
                'pubmeds'
            ])
            ->orderBy('sid')
            ->get();

        // Define CSV headers
        $headers = [
            'uuid',
            'submission_id',
            'gene_curie',
            'gene_symbol',
            'disease_curie',
            'disease_title',
            'disease_original_curie',
            'disease_original_title',
            'classification_curie',
            'classification_title',
            'moi_curie',
            'moi_title',
            'submitter_curie',
            'submitter_title',
            'submitted_as_assertion_criteria_url',
            'submitted_as_report_url',
            'submitted_as_pmids',
            'submitted_as_notes_public',
            'submitted_as_date',
            'submitted_run_date',
        ];

        // Build CSV content
        $csvContent = $this->arrayToCsvLine($headers);

        foreach ($submissions as $submission) {
            // Extract PMIDs from pubmeds relationship
            $pmids = $submission->pubmeds->pluck('pmid')->implode(';');

            // Extract data from submission_data JSON
            $submissionData = $submission->submission_data;
            $criteriaUrl = $submissionData->criteria->url ?? '';
            $reportUrl = $submissionData->report->ext_url ?? $submission->report_url ?? '';
            $publicNotes = $submissionData->notes->display ?? '';
            $submittedAsDate = $submissionData->report->display_date ?? $submission->report_date?->format('Y-m-d') ?? '';

            $row = [
                $submission->uuid ?? $submission->ident,
                $submission->sid,
                $submission->gene->hgnc_id ?? '',
                $submission->gene->symbol ?? '',
                $submission->disease->curie ?? '',
                $submission->disease->name ?? '',
                $submission->originalDisease->curie ?? $submission->disease->curie ?? '',
                $submission->originalDisease->name ?? $submission->disease->name ?? '',
                $submission->classification->curie ?? '',
                $submission->classification->name ?? '',
                $submission->inheritance->curie ?? '',
                $submission->inheritance->name ?? '',
                $submission->submitter->curie ?? '',
                $submission->submitter->name ?? '',
                $criteriaUrl,
                $reportUrl,
                $pmids,
                $publicNotes,
                $submittedAsDate,
                $submission->released_at?->format('Y-m-d') ?? '',
            ];

            $csvContent .= $this->arrayToCsvLine($row);
        }

        // Write the timestamped file
        $timestampedPath = $exportsDir . '/' . $timestampedFilename;
        File::put($timestampedPath, $csvContent);

        // Create/update the "latest" file (copy, not symlink for better compatibility)
        $latestPath = $exportsDir . '/' . $latestFilename;
        File::copy($timestampedPath, $latestPath);

        $this->info("Submissions CSV generated: {$timestampedPath}");
        $this->info("Latest CSV available at: {$latestPath}");

        \Log::info("GenCC Release: Submissions CSV generated", [
            'timestamped_file' => $timestampedFilename,
            'latest_file' => $latestFilename,
            'submission_count' => $submissions->count(),
        ]);
    }

    /**
     * Convert an array to a properly escaped CSV line.
     */
    protected function arrayToCsvLine(array $fields): string
    {
        $escaped = array_map(function ($field) {
            // Handle null values
            if ($field === null) {
                return '';
            }

            // Convert to string
            $field = (string) $field;

            // Escape fields containing commas, quotes, or newlines
            if (strpos($field, ',') !== false || strpos($field, '"') !== false || strpos($field, "\n") !== false) {
                return '"' . str_replace('"', '""', $field) . '"';
            }

            return $field;
        }, $fields);

        return implode(',', $escaped) . "\n";
    }
}
