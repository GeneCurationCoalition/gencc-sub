<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;

use App\Models\Job;
use App\Models\Action;
use App\Models\Submission;
use App\Models\Submitter;
use App\Models\Release;

use App\Services\JobStateMachine;
use App\Services\SubmissionStateMachine;

use App\Events\PublishStatusUpdate;

class GenccRelease extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gencc:release {arg=process} {--user_id=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Release pending submissions';

    /**
     * Release statistics for tracking
     */
    protected $releaseStats = [
        'new' => 0,
        'republish' => 0,
        'unpublish' => 0,
        'by_submitter' => [],
        'jobs_processed' => [],
        'actions_processed' => [],
    ];

    /**
     * Track generated filenames for the Release record.
     */
    protected ?string $releaseNotesFile = null;
    protected ?string $submissionsCsvFile = null;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $arg = $this->argument('arg');

        switch ($arg)
        {
            case 'process':
                $startTime = Carbon::now();

                // Check if there's anything to process before starting
                $pendingJobs = $this->getPendingJobs();
                $pendingActions = Action::status(Action::STATUS_PENDING)->get();

                if ($pendingJobs->count() === 0 && $pendingActions->count() === 0) {
                    $this->info("No jobs or actions to process. Skipping release.");
                    \Log::info('GenCC Release: No pending jobs or actions to process.');
                    return 0;
                }

                $this->process_actions($pendingActions);
                $this->process_jobs($pendingJobs);
                $this->generateReleaseNotes();
                $this->generateSubmissionsCsv();
                $this->updateSubmitterCounts();
                $this->createReleaseRecord($startTime);
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
     * Release all pending jobs.
     */
    protected function process_jobs($jobs)
    {
        $this->info("Found {$jobs->count()} submitted jobs to release");

        if ($jobs->count() === 0) {
            return 0;
        }

        foreach ($jobs as $job)
        {
            if ($job->status == Job::STATUS_PROCESSED) {
                $this->warn("Skipping job {$job->slug} - already processed");
                continue;
            }

            $this->info("Releasing job {$job->slug} (status: {$job->status}) with {$job->submissions->count()} submissions");

            $this->trackJobStatistics($job);

            $job->update(['is_publishing' => true]);
            PublishStatusUpdate::dispatch($job->slug, true, 'started');

            try {
                $processedCount = $this->releaseJob($job);

                $this->info("Job {$job->slug} released: {$processedCount} submissions processed");

                $this->releaseStats['jobs_processed'][] = [
                    'job_id' => $job->id,
                    'slug' => $job->slug,
                    'submitter_name' => $job->submitter->name ?? 'Unknown',
                    'submission_count' => $job->submissions->count(),
                ];

                $job->update(['is_publishing' => false]);
                PublishStatusUpdate::dispatch($job->slug, false, 'completed');

            } catch (\Exception $e) {
                $job->update(['is_publishing' => false]);
                PublishStatusUpdate::dispatch($job->slug, false, 'failed');

                $this->error("Failed to release job {$job->slug}: " . $e->getMessage());
                \Log::error("GenccRelease: Failed to release job {$job->slug}", [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        return 0;
    }

    /**
     * Release a single job's submissions within a transaction.
     */
    protected function releaseJob(Job $job): int
    {
        return DB::transaction(function () use ($job) {
            $processedSubmissions = [];

            foreach ($job->submissions as $submission) {
                if (in_array($submission->status, [Submission::STATUS_PUBLISHED, Submission::STATUS_UNPUBLISHED])) {
                    continue;
                }

                if (!$submission->status) {
                    \Log::warning("Submission {$submission->sid} has no status - skipping");
                    continue;
                }

                $targetState = match($submission->status) {
                    Submission::STATUS_NEW => Submission::STATUS_PUBLISHED,
                    Submission::STATUS_REPUBLISH => Submission::STATUS_PUBLISHED,
                    Submission::STATUS_UNPUBLISH => Submission::STATUS_UNPUBLISHED,
                    default => null
                };

                $actionType = match($submission->status) {
                    Submission::STATUS_NEW => 'published',
                    Submission::STATUS_REPUBLISH => 'republished',
                    Submission::STATUS_UNPUBLISH => 'unpublished',
                    default => 'published'
                };

                if ($targetState) {
                    SubmissionStateMachine::transition($submission, $targetState);

                    // Archive all other versions of this SID
                    Submission::where('sid', $submission->sid)
                        ->where('id', '!=', $submission->id)
                        ->update([
                            'is_most_recent' => false,
                            'is_live' => false
                        ]);

                    $submission->is_most_recent = true;
                    $submission->is_live = true;

                    if ($targetState === Submission::STATUS_PUBLISHED) {
                        $submission->released_at = Carbon::now();
                        $submission->original_submission_data = $submission->submission_data;
                    }

                    if ($targetState === Submission::STATUS_UNPUBLISHED) {
                        $submission->unpublished_at = Carbon::now();
                    }

                    $submission->save();
                } else {
                    $submission->update([
                        'released_at' => Carbon::now(),
                        'original_submission_data' => $submission->submission_data
                    ]);
                }

                $processedSubmissions[] = [
                    'sid' => $submission->sid,
                    'display_id' => $submission->display_id,
                    'action' => $actionType,
                    'classification_id' => $submission->classification_id
                ];
            }

            // Update processed_submission_ids on the job
            if (!empty($processedSubmissions)) {
                $existingProcessed = $job->processed_submission_ids ?? [];

                $allProcessed = $existingProcessed;
                foreach ($processedSubmissions as $newEntry) {
                    $existingIndex = array_search($newEntry['sid'], array_column($allProcessed, 'sid'));
                    if ($existingIndex !== false) {
                        $allProcessed[$existingIndex] = $newEntry;
                    } else {
                        $allProcessed[] = $newEntry;
                    }
                }

                $job->processed_submission_ids = $allProcessed;
            }

            // Mark the job as processed
            if ($job->status) {
                JobStateMachine::complete($job);
                $job->save();
            } else {
                $job->update(['status' => Job::STATUS_PROCESSED]);
            }

            return count($processedSubmissions);
        });
    }


    /**
     * Process all pending actions.
     */
    protected function process_actions($actions)
    {
        if ($actions->count() === 0) {
            return 0;
        }

        // Eager load relationships if not already loaded
        $actions->load(['submission', 'submission.submitter', 'submission.gene', 'submission.disease']);

        $this->info("Found {$actions->count()} pending actions to process");

        foreach ($actions as $action)
        {
            switch ($action->type)
            {
                case Action::TYPE_UNPUBLISH:
                    $action->update(['status' => Action::STATUS_COMPLETE]);

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

        return 0;
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
        $content .= "| **Total Changes** | **{$totalChanges}** |\n\n";

        // Jobs processed section
        if (!empty($this->releaseStats['jobs_processed'])) {
            $content .= "## Jobs Processed\n\n";
            $content .= "| Job | Submitter | Submissions |\n";
            $content .= "|-----|-----------|-------------|\n";

            foreach ($this->releaseStats['jobs_processed'] as $job) {
                $content .= "| {$job['slug']} | {$job['submitter_name']} | {$job['submission_count']} |\n";
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

        $this->releaseNotesFile = $filename;
        $this->info("Release notes generated: {$filepath}");
        \Log::info("GenCC Release: Release notes generated at {$filepath}", [
            'new' => $this->releaseStats['new'],
            'republish' => $this->releaseStats['republish'],
            'unpublish' => $this->releaseStats['unpublish'],
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
     * Update the counts JSON field on each submitter with their curation statistics.
     * This pre-computes counts by classification so gencc-search doesn't need to query them.
     */
    protected function updateSubmitterCounts(): void
    {
        $this->info("Updating submitter curation counts...");

        // Query live submissions grouped by submitter and classification
        $counts = Submission::where('is_live', true)
            ->join('submitters', 'submissions.submitter_id', '=', 'submitters.id')
            ->join('classifications', 'submissions.classification_id', '=', 'classifications.id')
            ->select(
                'submissions.submitter_id',
                'classifications.name as classification_name',
                'classifications.abbreviation as classification_abbr',
                DB::raw('count(*) as count')
            )
            ->groupBy('submissions.submitter_id', 'classifications.name', 'classifications.abbreviation')
            ->get();

        // Also get total live count per submitter
        $totals = Submission::where('is_live', true)
            ->select('submitter_id', DB::raw('count(*) as total'))
            ->groupBy('submitter_id')
            ->pluck('total', 'submitter_id');

        // Group by submitter_id
        $submitterCounts = [];
        foreach ($counts as $row) {
            if (!isset($submitterCounts[$row->submitter_id])) {
                $submitterCounts[$row->submitter_id] = [
                    'total' => $totals[$row->submitter_id] ?? 0,
                    'by_classification' => [],
                ];
            }
            $submitterCounts[$row->submitter_id]['by_classification'][$row->classification_name] = [
                'count' => $row->count,
                'abbreviation' => $row->classification_abbr,
            ];
        }

        // Update each submitter's counts field
        $updated = 0;
        foreach ($submitterCounts as $submitterId => $countsData) {
            Submitter::where('id', $submitterId)->update([
                'counts' => json_encode($countsData),
            ]);
            $updated++;
        }

        // Also clear counts for submitters with no live submissions
        $submittersWithCounts = array_keys($submitterCounts);
        if (!empty($submittersWithCounts)) {
            $cleared = Submitter::whereNotIn('id', $submittersWithCounts)
                ->where('counts', '!=', '[]')
                ->update(['counts' => '[]']);

            if ($cleared > 0) {
                $this->info("Cleared counts for {$cleared} submitters with no live submissions.");
            }
        }

        $this->info("Updated curation counts for {$updated} submitters.");
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
            // Extract PMIDs - prefer normalized_pmids column, fallback to pubmeds relationship
            // normalized_pmids uses comma separator, but export uses semicolon
            $pmids = $submission->normalized_pmids
                ? str_replace(',', ';', $submission->normalized_pmids)
                : $submission->pubmeds->pluck('pmid')->implode(';');

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

        $this->submissionsCsvFile = $timestampedFilename;
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
    /**
     * Create a Release record with statistics from this release run.
     */
    protected function createReleaseRecord(Carbon $startTime)
    {
        $endTime = Carbon::now();
        $duration = $startTime->diffInSeconds($endTime);

        $totalChanges = $this->releaseStats['new']
            + $this->releaseStats['republish']
            + $this->releaseStats['unpublish'];

        // Gather cumulative stats snapshot
        $cumulativeStats = $this->gatherCumulativeStatistics();

        $release = Release::create([
            'released_at' => $startTime,
            'release_notes_file' => $this->releaseNotesFile,
            'submissions_csv_file' => $this->submissionsCsvFile,
            'user_id' => $this->option('user_id') ? (int)$this->option('user_id') : null,
            'new_count' => $this->releaseStats['new'],
            'republish_count' => $this->releaseStats['republish'],
            'unpublish_count' => $this->releaseStats['unpublish'],
            'failed_count' => 0,
            'total_count' => $totalChanges,
            'jobs_processed' => $this->releaseStats['jobs_processed'],
            'by_submitter' => !empty($this->releaseStats['by_submitter']) ? $this->releaseStats['by_submitter'] : null,
            'cumulative_stats' => $cumulativeStats,
            'duration_seconds' => $duration,
        ]);

        $this->info("Release record created: {$release->slug}");
        \Log::info("GenCC Release: Release record {$release->slug} created", [
            'new' => $this->releaseStats['new'],
            'republish' => $this->releaseStats['republish'],
            'unpublish' => $this->releaseStats['unpublish'],
            'duration' => $duration,
        ]);
    }

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
