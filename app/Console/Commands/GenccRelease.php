<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Carbon\Carbon;
use Google\Cloud\Storage\StorageClient;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv as CsvWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;

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
    protected $description = 'Release pending submissions (process|repair|bootstrap)';

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
     * Track the release slug (predicted or explicit for bootstrap).
     */
    protected ?string $releaseSlug = null;

    /**
     * GCS bucket instance (lazy-loaded).
     */
    protected $gcsBucket = null;

    /**
     * Get the GCS bucket for storing release files.
     * Returns null if GCS is not configured.
     */
    protected function getGcsBucket()
    {
        if ($this->gcsBucket === null) {
            $bucketName = config('filesystems.disks.gcs.bucket');
            if (empty($bucketName)) {
                return null;
            }

            try {
                $storage = new StorageClient([
                    'projectId' => config('filesystems.disks.gcs.project_id'),
                ]);
                $this->gcsBucket = $storage->bucket($bucketName);
            } catch (\Exception $e) {
                $this->warn("GCS not available: {$e->getMessage()}. Files will be stored locally.");
                return null;
            }
        }

        return $this->gcsBucket;
    }

    /**
     * Upload content to GCS and return the object name.
     * Falls back to local storage if GCS is not configured.
     *
     * @param string $content File content
     * @param string $objectName GCS object name (path within bucket)
     * @param string $contentType MIME type
     * @param string|null $localFallbackPath Local path for fallback storage
     * @return string The object name or local filename
     */
    protected function uploadToGcs(string $content, string $objectName, string $contentType, ?string $localFallbackPath = null): string
    {
        $bucket = $this->getGcsBucket();
        $prefix = config('filesystems.disks.gcs.path_prefix', 'releases');
        $fullObjectName = $prefix ? "{$prefix}/{$objectName}" : $objectName;

        if ($bucket) {
            try {
                $bucket->upload($content, [
                    'name' => $fullObjectName,
                    'metadata' => [
                        'contentType' => $contentType,
                    ],
                ]);
                $this->info("  Uploaded to GCS: {$fullObjectName}");
                return $objectName;
            } catch (\Exception $e) {
                $this->warn("  GCS upload failed: {$e->getMessage()}. Falling back to local storage.");
            }
        }

        // Fallback to local storage
        if ($localFallbackPath) {
            $dir = dirname($localFallbackPath);
            if (!File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }
            File::put($localFallbackPath, $content);
            $this->info("  Stored locally: {$localFallbackPath}");
        }

        return $objectName;
    }

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

            case 'repair':
                $this->repairRelease();
                break;

            case 'bootstrap':
                return $this->bootstrapRelease();

            default:
                $this->error("Invalid argument: {$arg}");
                return 1;
        }
    }

    /**
     * Bootstrap an initial release record after database restore.
     *
     * This creates a GCC-00000 release record representing all currently live
     * submissions without processing any jobs. Use this after:
     * - Restoring a database from backup
     * - Initial deployment with existing data
     *
     * This command generates the CSV and release notes for all live submissions
     * and uploads them to GCS (with local fallback).
     *
     * @return int
     */
    protected function bootstrapRelease(): int
    {
        $startTime = Carbon::now();

        $this->info("=== GenCC Release Bootstrap ===");
        $this->info("");

        // Check if any releases already exist (bootstrap should be the first)
        $existingBootstrap = Release::first();
        if ($existingBootstrap) {
            $this->error("A release already exists ({$existingBootstrap->slug}, created {$existingBootstrap->released_at}).");
            $this->error("Bootstrap should only be run once after database restore when no releases exist.");
            return 1;
        }

        // Check current state
        $liveSubmissions = Submission::where('is_live', true)->count();
        $releasedJobs = Job::where('status', Job::STATUS_RELEASED)->count();
        $latestRelease = Release::orderBy('id', 'desc')->first();

        $this->info("Current State:");
        $this->info("  Live submissions: {$liveSubmissions}");
        $this->info("  Released jobs: {$releasedJobs}");
        $this->info("  Existing releases: " . ($latestRelease ? $latestRelease->slug : "None"));
        $this->info("");

        if ($liveSubmissions === 0) {
            $this->error("No live submissions found. Nothing to bootstrap.");
            return 1;
        }

        // Confirm before proceeding
        if (!$this->option('no-interaction')) {
            $this->warn("This will create an initial release record (GCC-00000) representing");
            $this->warn("all {$liveSubmissions} currently live submissions.");
            $this->info("");
            if (!$this->confirm("Proceed with bootstrap?", true)) {
                $this->info("Bootstrap cancelled.");
                return 0;
            }
        }

        $this->info("Starting bootstrap...");
        $this->info("");

        // Gather statistics from live submissions (no new/republish/unpublish - just totals)
        $this->info("1. Gathering submission statistics...");
        $this->gatherBootstrapStatistics();

        // Generate release notes
        $this->info("2. Generating release notes...");
        $this->generateBootstrapReleaseNotes();

        // Generate submissions CSV
        $this->info("3. Generating submissions CSV...");
        $this->generateSubmissionsCsv();

        // Update submitter counts
        $this->info("4. Updating submitter counts...");
        $this->updateSubmitterCounts();

        // Create the bootstrap release record with slug GCC-00000
        $this->info("5. Creating bootstrap release record...");
        $this->createBootstrapReleaseRecord($startTime);

        $this->info("");
        $this->info("=== Bootstrap Complete ===");

        $duration = $startTime->diffInSeconds(Carbon::now());
        $this->info("Duration: {$duration} seconds");
        $this->info("Live submissions captured: {$liveSubmissions}");

        \Log::info("GenCC Release: Bootstrap complete", [
            'live_submissions' => $liveSubmissions,
            'duration' => $duration,
        ]);

        return 0;
    }

    /**
     * Gather statistics for bootstrap (all live submissions, no changes).
     */
    protected function gatherBootstrapStatistics(): void
    {
        // For bootstrap, we don't have new/republish/unpublish counts
        // All live submissions are considered as the baseline
        $this->releaseStats = [
            'new' => 0,
            'republish' => 0,
            'unpublish' => 0,
            'by_submitter' => [],
            'jobs_processed' => [],
            'actions_processed' => [],
        ];

        // Count live submissions by submitter using aggregation (memory efficient)
        $submitterCounts = Submission::where('is_live', true)
            ->join('submitters', 'submissions.submitter_id', '=', 'submitters.id')
            ->select('submitters.name', DB::raw('count(*) as count'))
            ->groupBy('submitters.name')
            ->pluck('count', 'name');

        $totalLive = 0;
        foreach ($submitterCounts as $name => $count) {
            $this->releaseStats['by_submitter'][$name] = [
                'new' => 0,
                'republish' => 0,
                'unpublish' => 0,
                'live' => $count,
            ];
            $totalLive += $count;
        }

        $this->info("  Found {$totalLive} released submissions from " .
            count($this->releaseStats['by_submitter']) . " submitters");

        // Gather released jobs with submission counts using aggregation
        // Use a subquery for submission counts to avoid memory issues
        $releasedJobs = Job::where('jobs.status', Job::STATUS_RELEASED)
            ->join('submitters', 'jobs.submitter_id', '=', 'submitters.id')
            ->leftJoin('submissions', function ($join) {
                $join->on('submissions.job_id', '=', 'jobs.id')
                     ->where('submissions.is_live', true);
            })
            ->select(
                'jobs.id',
                'jobs.slug',
                'jobs.released_at',
                'submitters.name as submitter_name',
                DB::raw('count(submissions.id) as submission_count')
            )
            ->groupBy('jobs.id', 'jobs.slug', 'jobs.released_at', 'submitters.name')
            ->orderBy('jobs.released_at')
            ->get();

        foreach ($releasedJobs as $job) {
            $this->releaseStats['jobs_processed'][] = [
                'job_id' => $job->id,
                'slug' => $job->slug,
                'submitter_name' => $job->submitter_name ?? 'Unknown',
                'submission_count' => $job->submission_count,
                'released_at' => $job->released_at?->toIso8601String(),
            ];
        }

        $this->info("  Found " . count($this->releaseStats['jobs_processed']) . " released jobs");
    }

    /**
     * Generate release notes specifically for bootstrap (no changes, just state).
     */
    protected function generateBootstrapReleaseNotes(): void
    {
        $releaseDate = Carbon::now();
        // Predict slug using the model's sequential numbering (should be GCC-00000 for bootstrap)
        $nextNumber = Release::getNextSlugNumber();
        $this->releaseSlug = 'GCC-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
        $filename = 'Release_Notes_' . $releaseDate->format('Y-m-d_His') . '.txt';

        // Ensure the releases directory exists
        $releasesDir = storage_path('releases');
        if (!File::isDirectory($releasesDir)) {
            File::makeDirectory($releasesDir, 0755, true);
        }

        // Gather cumulative statistics
        $cumulativeStats = $this->gatherCumulativeStatistics();

        // Build plain text content
        $separator = str_repeat('=', 72);
        $thinSeparator = str_repeat('-', 72);
        $appVersion = config('app.version', 'dev');

        $content = "{$separator}\n";
        $content .= "            GenCC {$appVersion} RELEASE NOTES - {$this->releaseSlug}\n";
        $content .= "{$separator}\n\n";
        $content .= "Release: {$this->releaseSlug}\n";
        $content .= "Date: {$releaseDate->format('F j, Y g:i A T')}\n";
        $content .= "Type: Initial Release (Database Restore)\n";
        $content .= "\n";

        // Summary section
        $content .= "{$thinSeparator}\n";
        $content .= "SUMMARY\n";
        $content .= "{$thinSeparator}\n\n";
        $content .= "This is a bootstrap release created after database restore.\n";
        $content .= "It captures the current state of all released submissions.\n";
        $content .= "No new changes were processed in this release.\n";
        $content .= "\n";

        // Jobs included section (sorted by submission count descending)
        if (!empty($this->releaseStats['jobs_processed'])) {
            $content .= "{$thinSeparator}\n";
            $content .= "JOBS INCLUDED (" . count($this->releaseStats['jobs_processed']) . " total)\n";
            $content .= "{$thinSeparator}\n\n";
            $content .= sprintf("  %-12s %-40s %12s\n", "Job ID", "Submitter", "Submissions");
            $content .= "  " . str_repeat('-', 66) . "\n";

            // Sort by submission count descending
            $sortedJobs = $this->releaseStats['jobs_processed'];
            usort($sortedJobs, fn($a, $b) => $b['submission_count'] <=> $a['submission_count']);

            foreach ($sortedJobs as $job) {
                $submitterName = mb_strlen($job['submitter_name']) > 38
                    ? mb_substr($job['submitter_name'], 0, 35) . '...'
                    : $job['submitter_name'];
                $content .= sprintf("  %-12s %-40s %12s\n",
                    $job['slug'],
                    $submitterName,
                    number_format($job['submission_count'])
                );
            }
            $content .= "\n";
        }

        // Current State section
        $content .= "{$separator}\n";
        $content .= "                    CURRENT STATE (ALL SUBMISSIONS)\n";
        $content .= "{$separator}\n\n";

        // Overall totals
        $content .= "OVERALL TOTALS:\n\n";
        $content .= sprintf("  %-40s %10s\n", "Total Released Submissions", number_format($cumulativeStats['total_live']));
        $content .= sprintf("  %-40s %10s\n", "Total Published (including archived)", number_format($cumulativeStats['total_published']));
        $content .= sprintf("  %-40s %10s\n", "Total Unpublished", number_format($cumulativeStats['total_unpublished']));
        $content .= sprintf("  %-40s %10s\n", "Unique Gene-Disease Pairs", number_format($cumulativeStats['unique_gene_disease']));
        $content .= sprintf("  %-40s %10s\n", "Unique Genes", number_format($cumulativeStats['unique_genes']));
        $content .= sprintf("  %-40s %10s\n", "Unique Diseases", number_format($cumulativeStats['unique_diseases']));
        $content .= "\n";

        // Classification breakdown
        if (!empty($cumulativeStats['by_classification'])) {
            $content .= "BY CLASSIFICATION:\n\n";
            foreach ($cumulativeStats['by_classification'] as $classification => $count) {
                $content .= sprintf("  %-40s %10s\n", $classification, number_format($count));
            }
            $content .= "\n";
        }

        // All submissions by submitter
        if (!empty($cumulativeStats['by_submitter'])) {
            $content .= "ALL SUBMISSIONS BY SUBMITTER:\n\n";
            $content .= sprintf("  %-40s %8s %10s %12s\n", "Submitter", "Released", "Published", "Unpublished");
            $content .= "  " . str_repeat('-', 72) . "\n";

            foreach ($cumulativeStats['by_submitter'] as $submitter => $stats) {
                $displayName = mb_strlen($submitter) > 38 ? mb_substr($submitter, 0, 35) . '...' : $submitter;
                $content .= sprintf("  %-40s %8s %10s %12s\n",
                    $displayName,
                    number_format($stats['live']),
                    number_format($stats['published']),
                    number_format($stats['unpublished'])
                );
            }
            $content .= "\n";
        }

        // Footer
        $content .= "{$separator}\n";
        $content .= "Generated automatically by GenCC Release Bootstrap\n";
        $content .= "{$separator}\n";

        // Upload to GCS (with local fallback)
        $localFallbackPath = $releasesDir . '/' . $filename;
        $this->uploadToGcs($content, "notes/{$filename}", 'text/plain', $localFallbackPath);

        // Also create/overwrite Release_Notes.txt as a copy of the latest
        $latestNotesPath = $releasesDir . '/Release_Notes.txt';
        $this->uploadToGcs($content, "notes/Release_Notes.txt", 'text/plain', $latestNotesPath);

        $this->releaseNotesFile = $filename;
        $this->info("  Release notes generated: {$filename}");
        $this->info("  Latest notes available as: Release_Notes.txt");

        \Log::info("GenCC Release: Bootstrap release notes generated", [
            'filename' => $filename,
            'total_live' => $cumulativeStats['total_live'],
        ]);
    }

    /**
     * Create the bootstrap release record with slug GCC-00000.
     */
    protected function createBootstrapReleaseRecord(Carbon $startTime): void
    {
        $endTime = Carbon::now();
        $duration = $startTime->diffInSeconds($endTime);

        // Gather cumulative stats
        $cumulativeStats = $this->gatherCumulativeStatistics();
        $liveCount = $cumulativeStats['total_live'];

        // Build by_submitter from releaseStats (shows live counts per submitter)
        $bySubmitter = [];
        foreach ($this->releaseStats['by_submitter'] as $name => $stats) {
            $bySubmitter[$name] = [
                'new' => 0,
                'republish' => 0,
                'unpublish' => 0,
                'live' => $stats['live'] ?? 0,
            ];
        }

        $release = Release::create([
            // slug auto-generated as GCC-00000 (first release, count-based)
            'released_at' => $startTime,
            'release_notes_file' => $this->releaseNotesFile,
            'submissions_csv_file' => $this->submissionsCsvFile,
            'user_id' => $this->option('user_id') ? (int)$this->option('user_id') : null,
            'new_count' => 0,
            'republish_count' => 0,
            'unpublish_count' => 0,
            'failed_count' => 0,
            'total_count' => $liveCount,
            'jobs_processed' => $this->releaseStats['jobs_processed'],
            'errors' => ['note' => 'Bootstrap release created at ' . Carbon::now()->toIso8601String()],
            'by_submitter' => $bySubmitter,
            'cumulative_stats' => $cumulativeStats,
            'duration_seconds' => $duration,
        ]);

        $this->info("  Bootstrap release record created: {$release->slug}");
        $this->info("  Jobs included: " . count($this->releaseStats['jobs_processed']));

        \Log::info("GenCC Release: Bootstrap release record created", [
            'slug' => $release->slug,
            'total_live' => $liveCount,
            'jobs_count' => count($this->releaseStats['jobs_processed']),
            'duration' => $duration,
        ]);
    }

    /**
     * Repair a release that failed to complete.
     *
     * This method regenerates the release outputs (CSV, notes, submitter counts,
     * release record) without reprocessing jobs. Use this when:
     * - The queue job got stuck or timed out
     * - Jobs were marked as released but outputs weren't generated
     * - The release record is missing but data is consistent
     *
     * @return int
     */
    protected function repairRelease(): int
    {
        $startTime = Carbon::now();

        $this->info("=== GenCC Release Repair ===");
        $this->info("");

        // Check current state
        $liveSubmissions = Submission::where('is_live', true)->count();
        $releasedJobs = Job::where('status', Job::STATUS_RELEASED)->count();
        $latestRelease = Release::orderBy('id', 'desc')->first();

        $this->info("Current State:");
        $this->info("  Live submissions: {$liveSubmissions}");
        $this->info("  Released jobs: {$releasedJobs}");
        $this->info("  Latest release record: " . ($latestRelease ? "ID {$latestRelease->id} ({$latestRelease->released_at})" : "None"));
        $this->info("");

        if ($liveSubmissions === 0) {
            $this->error("No live submissions found. Nothing to repair.");
            return 1;
        }

        // Check if CSV file exists for today
        $releaseDate = Carbon::now();
        $csvFilename = 'gencc-submissions-' . $releaseDate->format('Y-m-d') . '.csv';
        $exportsDir = storage_path('app/public/exports');
        $csvExists = File::exists($exportsDir . '/' . $csvFilename);

        $this->info("Checking existing outputs:");
        $this->info("  CSV file ({$csvFilename}): " . ($csvExists ? "EXISTS" : "MISSING"));

        // Check release notes (check both .txt and legacy .md)
        $releasesDir = storage_path('releases');
        $notesTxtPattern = 'Release_Notes_' . $releaseDate->format('Y-m-d') . '*.txt';
        $notesMdPattern = 'Release_Notes_' . $releaseDate->format('Y-m-d') . '*.md';
        $notesFiles = array_merge(
            glob($releasesDir . '/' . $notesTxtPattern),
            glob($releasesDir . '/' . $notesMdPattern)
        );
        $notesExist = !empty($notesFiles);
        $this->info("  Release notes: " . ($notesExist ? "EXISTS (" . count($notesFiles) . " files)" : "MISSING"));
        $this->info("");

        // Confirm before proceeding
        if (!$this->option('no-interaction')) {
            if (!$this->confirm("Proceed with repair? This will regenerate all release outputs.", true)) {
                $this->info("Repair cancelled.");
                return 0;
            }
        }

        $this->info("Starting repair...");
        $this->info("");

        // Regenerate outputs
        $this->info("1. Generating release notes...");
        $this->generateReleaseNotes();

        $this->info("2. Generating submissions CSV...");
        $this->generateSubmissionsCsv();

        $this->info("3. Updating submitter counts...");
        $this->updateSubmitterCounts();

        $this->info("4. Creating release record...");
        $this->createRepairReleaseRecord($startTime);

        $this->info("");
        $this->info("=== Repair Complete ===");

        $duration = $startTime->diffInSeconds(Carbon::now());
        $this->info("Duration: {$duration} seconds");

        return 0;
    }

    /**
     * Create a release record specifically for a repair operation.
     */
    protected function createRepairReleaseRecord(Carbon $startTime): void
    {
        $releaseDate = Carbon::now();
        $duration = $startTime->diffInSeconds($releaseDate);

        // Find jobs that were released today (these are the jobs we're repairing for)
        $releasedJobsToday = Job::whereDate('released_at', $releaseDate->toDateString())
            ->with(['submitter', 'submissions'])
            ->get();

        // Build jobs_processed data from today's released jobs
        $jobsProcessed = [];
        $newCount = 0;
        $republishCount = 0;
        $unpublishCount = 0;
        $bySubmitter = [];

        foreach ($releasedJobsToday as $job) {
            $submitterName = $job->submitter->name ?? 'Unknown';
            $jobNewCount = $job->submissions->where('action', Submission::ACTION_NEW)->count();
            $jobRepublishCount = $job->submissions->where('action', Submission::ACTION_REPUBLISH)->count();
            $jobUnpublishCount = $job->submissions->where('action', Submission::ACTION_UNPUBLISH)->count();

            $jobsProcessed[] = [
                'slug' => $job->slug,
                'submitter_name' => $submitterName,
                'submission_count' => $job->submissions->count(),
                'new' => $jobNewCount,
                'republish' => $jobRepublishCount,
                'unpublish' => $jobUnpublishCount,
            ];

            $newCount += $jobNewCount;
            $republishCount += $jobRepublishCount;
            $unpublishCount += $jobUnpublishCount;

            // Track by submitter
            if (!isset($bySubmitter[$submitterName])) {
                $bySubmitter[$submitterName] = ['new' => 0, 'republish' => 0, 'unpublish' => 0];
            }
            $bySubmitter[$submitterName]['new'] += $jobNewCount;
            $bySubmitter[$submitterName]['republish'] += $jobRepublishCount;
            $bySubmitter[$submitterName]['unpublish'] += $jobUnpublishCount;
        }

        $this->info("  Found " . count($releasedJobsToday) . " job(s) released today");

        // Gather current statistics
        $liveCount = Submission::where('is_live', true)->count();

        // Get cumulative stats
        $cumulativeStats = $this->gatherCumulativeStatistics();

        // Check if release record already exists for today
        $existingRelease = Release::whereDate('released_at', $releaseDate->toDateString())->first();

        if ($existingRelease) {
            $this->info("  Updating existing release record (ID: {$existingRelease->id})...");
            $existingRelease->update([
                'release_notes_file' => $this->releaseNotesFile,
                'submissions_csv_file' => $this->submissionsCsvFile,
                'user_id' => $this->option('user_id') ?? $existingRelease->user_id,
                'new_count' => $newCount,
                'republish_count' => $republishCount,
                'unpublish_count' => $unpublishCount,
                'total_count' => $liveCount,
                'jobs_processed' => $jobsProcessed,
                'by_submitter' => $bySubmitter,
                'cumulative_stats' => $cumulativeStats,
                'duration_seconds' => $duration,
                'errors' => ['note' => 'Repaired at ' . Carbon::now()->toIso8601String()],
            ]);
            $this->info("  Release record updated.");
        } else {
            $this->info("  Creating new release record...");
            $release = Release::create([
                'released_at' => $releaseDate,
                'release_notes_file' => $this->releaseNotesFile,
                'submissions_csv_file' => $this->submissionsCsvFile,
                'user_id' => $this->option('user_id') ?? 1,
                'new_count' => $newCount,
                'republish_count' => $republishCount,
                'unpublish_count' => $unpublishCount,
                'failed_count' => 0,
                'total_count' => $liveCount,
                'jobs_processed' => $jobsProcessed,
                'errors' => ['note' => 'Created via repair at ' . Carbon::now()->toIso8601String()],
                'by_submitter' => $bySubmitter,
                'cumulative_stats' => $cumulativeStats,
                'duration_seconds' => $duration,
            ]);
            $this->info("  Release record created (ID: {$release->id}, Slug: {$release->slug})");
        }
    }

    /**
     * Get pending jobs ready for release.
     */
    protected function getPendingJobs()
    {
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

        // Diagnostic: Log the jobs being selected for release
        $jobSlugs = $jobs->pluck('slug')->toArray();
        \Log::info("GenCC Release: getPendingJobs() found " . count($jobSlugs) . " jobs with status='" . Job::STATUS_SUBMITTED . "'", [
            'job_slugs' => $jobSlugs,
            'statuses' => $jobs->pluck('status', 'slug')->toArray(),
        ]);

        return $jobs;
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

        // Diagnostic: Verify all jobs have been transitioned
        $jobIds = $jobs->pluck('id')->toArray();
        $freshJobs = Job::whereIn('id', $jobIds)->get();
        $statusSummary = [];
        foreach ($freshJobs as $fj) {
            $statusSummary[$fj->slug] = $fj->status;
        }
        \Log::info("GenCC Release: process_jobs() complete. Final job statuses:", $statusSummary);
        $this->info("Job status summary after release: " . json_encode($statusSummary));

        // Check if any jobs are still in 'submitted' status (they shouldn't be!)
        $stillSubmitted = $freshJobs->where('status', Job::STATUS_SUBMITTED)->pluck('slug')->toArray();
        if (!empty($stillSubmitted)) {
            \Log::error("GenCC Release: Jobs still have 'submitted' status after processing!", [
                'jobs' => $stillSubmitted,
            ]);
            $this->error("WARNING: The following jobs are still 'submitted' after processing: " . implode(', ', $stillSubmitted));
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
            $originalStatus = $job->status;
            if ($job->status) {
                JobStateMachine::complete($job);
                $job->save();

                // Diagnostic: Verify the status was persisted
                $freshJob = Job::find($job->id);
                if ($freshJob->status !== Job::STATUS_RELEASED) {
                    \Log::error("GenCC Release: Job {$job->slug} status NOT persisted!", [
                        'expected' => Job::STATUS_RELEASED,
                        'original_status' => $originalStatus,
                        'model_status_after_save' => $job->status,
                        'db_status' => $freshJob->status,
                    ]);
                    $this->error("WARNING: Job {$job->slug} status did not persist to {Job::STATUS_RELEASED}");
                } else {
                    \Log::info("GenCC Release: Job {$job->slug} transitioned from '{$originalStatus}' to '{$freshJob->status}'");
                }
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
     * Generate a release notes plain text file.
     */
    protected function generateReleaseNotes()
    {
        $releaseDate = Carbon::now();

        // Predict the release slug using the model's sequential numbering
        $nextNumber = Release::getNextSlugNumber();
        $this->releaseSlug = 'GCC-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);

        $filename = 'Release_Notes_' . $releaseDate->format('Y-m-d_His') . '.txt';

        // Ensure the releases directory exists
        $releasesDir = storage_path('releases');
        if (!File::isDirectory($releasesDir)) {
            File::makeDirectory($releasesDir, 0755, true);
        }

        $totalSubmissions = $this->releaseStats['new'] + $this->releaseStats['republish'];
        $totalChanges = $totalSubmissions + $this->releaseStats['unpublish'];

        // Gather cumulative statistics for all released submissions
        $cumulativeStats = $this->gatherCumulativeStatistics();

        // Build plain text content
        $separator = str_repeat('=', 72);
        $thinSeparator = str_repeat('-', 72);
        $appVersion = config('app.version', 'dev');

        $content = "{$separator}\n";
        $content .= "            GenCC {$appVersion} RELEASE NOTES - {$this->releaseSlug}\n";
        $content .= "{$separator}\n\n";
        $content .= "Release: {$this->releaseSlug}\n";
        $content .= "Date: {$releaseDate->format('F j, Y g:i A T')}\n";
        $content .= "\n";

        // Summary section
        $content .= "{$thinSeparator}\n";
        $content .= "SUMMARY\n";
        $content .= "{$thinSeparator}\n\n";
        $content .= sprintf("  %-30s %10s\n", "New Submissions", number_format($this->releaseStats['new']));
        $content .= sprintf("  %-30s %10s\n", "Updated Submissions", number_format($this->releaseStats['republish']));
        $content .= sprintf("  %-30s %10s\n", "Unpublished Submissions", number_format($this->releaseStats['unpublish']));
        $content .= "  " . str_repeat('-', 42) . "\n";
        $content .= sprintf("  %-30s %10s\n", "TOTAL CHANGES", number_format($totalChanges));
        $content .= "\n";

        // Jobs processed section (sorted by submission count descending)
        if (!empty($this->releaseStats['jobs_processed'])) {
            $content .= "{$thinSeparator}\n";
            $content .= "JOBS PROCESSED\n";
            $content .= "{$thinSeparator}\n\n";
            $content .= sprintf("  %-12s %-40s %12s\n", "Job ID", "Submitter", "Submissions");
            $content .= "  " . str_repeat('-', 66) . "\n";

            // Sort by submission count descending
            $sortedJobs = $this->releaseStats['jobs_processed'];
            usort($sortedJobs, fn($a, $b) => $b['submission_count'] <=> $a['submission_count']);

            foreach ($sortedJobs as $job) {
                $submitterName = mb_strlen($job['submitter_name']) > 38
                    ? mb_substr($job['submitter_name'], 0, 35) . '...'
                    : $job['submitter_name'];
                $content .= sprintf("  %-12s %-40s %12s\n",
                    $job['slug'],
                    $submitterName,
                    number_format($job['submission_count'])
                );
            }
            $content .= "\n";
        }

        // Breakdown by submitter section
        if (!empty($this->releaseStats['by_submitter'])) {
            $content .= "{$thinSeparator}\n";
            $content .= "BREAKDOWN BY SUBMITTER\n";
            $content .= "{$thinSeparator}\n\n";
            $content .= sprintf("  %-36s %8s %8s %10s %8s\n", "Submitter", "New", "Updated", "Unpublish", "Total");
            $content .= "  " . str_repeat('-', 72) . "\n";

            // Sort by total submissions descending
            $submitters = $this->releaseStats['by_submitter'];
            uasort($submitters, function ($a, $b) {
                $totalA = $a['new'] + $a['republish'] + $a['unpublish'];
                $totalB = $b['new'] + $b['republish'] + $b['unpublish'];
                return $totalB <=> $totalA;
            });

            foreach ($submitters as $name => $stats) {
                $total = $stats['new'] + $stats['republish'] + $stats['unpublish'];
                $displayName = mb_strlen($name) > 34 ? mb_substr($name, 0, 31) . '...' : $name;
                $content .= sprintf("  %-36s %8s %8s %10s %8s\n",
                    $displayName,
                    number_format($stats['new']),
                    number_format($stats['republish']),
                    number_format($stats['unpublish']),
                    number_format($total)
                );
            }
            $content .= "\n";
        }

        // Unpublished submissions detail section
        if (!empty($this->releaseStats['actions_processed'])) {
            $unpublishActions = array_filter($this->releaseStats['actions_processed'], fn($a) => $a['type'] === 'unpublish');
            if (!empty($unpublishActions)) {
                $content .= "{$thinSeparator}\n";
                $content .= "UNPUBLISHED SUBMISSIONS\n";
                $content .= "{$thinSeparator}\n\n";
                $content .= sprintf("  %-14s %-12s %-25s %s\n", "Submission ID", "Gene", "Disease", "Submitter");
                $content .= "  " . str_repeat('-', 68) . "\n";

                foreach ($unpublishActions as $action) {
                    $disease = mb_strlen($action['disease']) > 23 ? mb_substr($action['disease'], 0, 20) . '...' : $action['disease'];
                    $submitter = mb_strlen($action['submitter']) > 18 ? mb_substr($action['submitter'], 0, 15) . '...' : $action['submitter'];
                    $content .= sprintf("  %-14s %-12s %-25s %s\n",
                        $action['submission_sid'],
                        $action['gene'],
                        $disease,
                        $submitter
                    );
                }
                $content .= "\n";
            }
        }

        // Current State section - full accounting of all released submissions
        $content .= "{$separator}\n";
        $content .= "                    CURRENT STATE (ALL SUBMISSIONS)\n";
        $content .= "{$separator}\n\n";

        // Overall totals
        $content .= "OVERALL TOTALS:\n\n";
        $content .= sprintf("  %-40s %10s\n", "Total Released Submissions", number_format($cumulativeStats['total_live']));
        $content .= sprintf("  %-40s %10s\n", "Total Published (including archived)", number_format($cumulativeStats['total_published']));
        $content .= sprintf("  %-40s %10s\n", "Total Unpublished", number_format($cumulativeStats['total_unpublished']));
        $content .= sprintf("  %-40s %10s\n", "Unique Gene-Disease Pairs", number_format($cumulativeStats['unique_gene_disease']));
        $content .= sprintf("  %-40s %10s\n", "Unique Genes", number_format($cumulativeStats['unique_genes']));
        $content .= sprintf("  %-40s %10s\n", "Unique Diseases", number_format($cumulativeStats['unique_diseases']));
        $content .= "\n";

        // Classification breakdown
        if (!empty($cumulativeStats['by_classification'])) {
            $content .= "BY CLASSIFICATION:\n\n";
            foreach ($cumulativeStats['by_classification'] as $classification => $count) {
                $content .= sprintf("  %-40s %10s\n", $classification, number_format($count));
            }
            $content .= "\n";
        }

        // All submissions by submitter
        if (!empty($cumulativeStats['by_submitter'])) {
            $content .= "ALL SUBMISSIONS BY SUBMITTER:\n\n";
            $content .= sprintf("  %-40s %8s %10s %12s\n", "Submitter", "Released", "Published", "Unpublished");
            $content .= "  " . str_repeat('-', 72) . "\n";

            foreach ($cumulativeStats['by_submitter'] as $submitter => $stats) {
                $displayName = mb_strlen($submitter) > 38 ? mb_substr($submitter, 0, 35) . '...' : $submitter;
                $content .= sprintf("  %-40s %8s %10s %12s\n",
                    $displayName,
                    number_format($stats['live']),
                    number_format($stats['published']),
                    number_format($stats['unpublished'])
                );
            }
            $content .= "\n";
        }

        // Footer
        $content .= "{$separator}\n";
        $content .= "Generated automatically by GenCC Release Process\n";
        $content .= "{$separator}\n";

        // Upload to GCS (with local fallback)
        $localFallbackPath = $releasesDir . '/' . $filename;
        $this->uploadToGcs($content, "notes/{$filename}", 'text/plain', $localFallbackPath);

        // Also create/overwrite Release_Notes.txt as a copy of the latest
        $latestNotesPath = $releasesDir . '/Release_Notes.txt';
        $this->uploadToGcs($content, "notes/Release_Notes.txt", 'text/plain', $latestNotesPath);

        $this->releaseNotesFile = $filename;
        $this->info("Release notes generated: {$filename}");
        $this->info("Latest notes available as: Release_Notes.txt");
        \Log::info("GenCC Release: Release notes generated", [
            'filename' => $filename,
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
     * Uses the same format as gencc-search SubmissionExport for consistency.
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
        $timestampedFilename = 'gencc-submissions-' . $releaseDate->format('Y-m-d_His') . '.csv';
        $latestFilename = 'gencc-submissions.csv';

        // Get all live, published submissions with relationships
        // Note: gencc-search filters by submitter.downloadable=true for public downloads,
        // but for the release export we include all submissions
        $submissions = Submission::where('is_live', true)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->with([
                'gene',
                'disease',
                'originalDisease',
                'classification',
                'inheritance',
                'submitter',
            ])
            ->orderBy('sid')
            ->get();

        // Define CSV headers - matches gencc-search SubmissionExport format exactly
        $headers = [
            'sgc_id',
            'version_number',
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
            'submitted_as_hgnc_id',
            'submitted_as_hgnc_symbol',
            'submitted_as_disease_id',
            'submitted_as_disease_name',
            'submitted_as_moi_id',
            'submitted_as_moi_name',
            'submitted_as_submitter_id',
            'submitted_as_submitter_name',
            'submitted_as_classification_id',
            'submitted_as_classification_name',
            'submitted_as_date',
            'submitted_as_public_report_url',
            'submitted_as_notes',
            'submitted_as_pmids',
            'submitted_as_assertion_criteria_url',
            'submitted_as_submission_id',
            'submitted_run_date',
        ];

        // Build CSV content
        $csvContent = $this->arrayToCsvLine($headers);

        foreach ($submissions as $submission) {
            // Extract data from original_submission_data JSON (what was originally submitted)
            $originalData = $submission->original_submission_data;

            // Handle both object and array access (JSON cast can return either)
            $getNestedValue = function($data, $keys, $default = '') use (&$getNestedValue) {
                if ($data === null) return $default;
                foreach ($keys as $key) {
                    if (is_object($data)) {
                        $data = $data->$key ?? null;
                    } elseif (is_array($data)) {
                        $data = $data[$key] ?? null;
                    } else {
                        return $default;
                    }
                    if ($data === null) return $default;
                }
                return $data;
            };

            // Extract submitted_as fields from original_submission_data
            $submittedAsHgncId = $getNestedValue($originalData, ['gene', 'id']);
            $submittedAsHgncSymbol = $getNestedValue($originalData, ['gene', 'symbol']);
            $submittedAsDiseaseId = $getNestedValue($originalData, ['disease', 'id']);
            $submittedAsDiseaseName = $getNestedValue($originalData, ['disease', 'name']);
            $submittedAsMoiId = $getNestedValue($originalData, ['moi', 'id']);
            $submittedAsMoiName = $getNestedValue($originalData, ['moi', 'name']);
            $submittedAsSubmitterId = $getNestedValue($originalData, ['additional_information', 'submitter_curie']);
            $submittedAsSubmitterName = $getNestedValue($originalData, ['additional_information', 'submitter_title']);
            $submittedAsClassificationId = $getNestedValue($originalData, ['classification', 'id']);
            $submittedAsClassificationName = $getNestedValue($originalData, ['classification', 'name']);
            $submittedAsDate = $getNestedValue($originalData, ['report', 'display_date']);
            $submittedAsReportUrl = $getNestedValue($originalData, ['report', 'ext_url']);
            // Notes come from submission_data (current/editable) rather than original_submission_data
            $submittedAsNotes = $getNestedValue($submission->submission_data, ['notes', 'display']);
            $submittedAsCriteriaUrl = $getNestedValue($originalData, ['criteria', 'url']);
            $submittedAsSubmissionId = $getNestedValue($originalData, ['additional_information', 'submitted_as_submission_id']);

            // PMIDs - add space after commas for readability (matches gencc-search format)
            $pmids = $submission->normalized_pmids
                ? str_replace(',', ', ', $submission->normalized_pmids)
                : '';

            // submitted_run_date is the release date (date portion only)
            $submittedRunDate = $submission->released_at?->format('Y-m-d') ?? '';

            $row = [
                $submission->sid,                                           // sgc_id
                $submission->version_number ?? 1,                           // version_number
                $submission->gene->hgnc_id ?? '',                           // gene_curie (HGNC ID)
                $submission->gene->symbol ?? '',                            // gene_symbol
                $submission->disease->curie ?? '',                          // disease_curie
                $submission->disease->name ?? '',                           // disease_title
                $submission->originalDisease->curie ?? '',                  // disease_original_curie
                $submission->originalDisease->name ?? '',                   // disease_original_title
                $submission->classification->curie ?? '',                   // classification_curie
                $submission->classification->name ?? '',                    // classification_title
                $submission->inheritance->curie ?? '',                      // moi_curie
                $submission->inheritance->name ?? '',                       // moi_title
                $submission->submitter->curie ?? '',                        // submitter_curie
                $submission->submitter->name ?? '',                         // submitter_title
                $submittedAsHgncId,                                         // submitted_as_hgnc_id
                $submittedAsHgncSymbol,                                     // submitted_as_hgnc_symbol
                $submittedAsDiseaseId,                                      // submitted_as_disease_id
                $submittedAsDiseaseName,                                    // submitted_as_disease_name
                $submittedAsMoiId,                                          // submitted_as_moi_id
                $submittedAsMoiName,                                        // submitted_as_moi_name
                $submittedAsSubmitterId,                                    // submitted_as_submitter_id
                $submittedAsSubmitterName,                                  // submitted_as_submitter_name
                $submittedAsClassificationId,                               // submitted_as_classification_id
                $submittedAsClassificationName,                             // submitted_as_classification_name
                $submittedAsDate,                                           // submitted_as_date
                $submittedAsReportUrl,                                      // submitted_as_public_report_url
                $submittedAsNotes,                                          // submitted_as_notes
                $pmids,                                                     // submitted_as_pmids
                $submittedAsCriteriaUrl,                                    // submitted_as_assertion_criteria_url
                $submittedAsSubmissionId,                                   // submitted_as_submission_id
                $submittedRunDate,                                          // submitted_run_date
            ];

            $csvContent .= $this->arrayToCsvLine($row);
        }

        // Upload timestamped file to GCS (with local fallback)
        $timestampedPath = $exportsDir . '/' . $timestampedFilename;
        $this->uploadToGcs($csvContent, "csv/{$timestampedFilename}", 'text/csv', $timestampedPath);

        // Also upload "latest" version for easy access
        $latestPath = $exportsDir . '/' . $latestFilename;
        $this->uploadToGcs($csvContent, "csv/{$latestFilename}", 'text/csv', $latestPath);

        // Generate additional formats (xlsx, xls, tsv)
        $this->generateAdditionalFormats($exportsDir, $timestampedFilename, $headers, $submissions);

        $this->submissionsCsvFile = $timestampedFilename;
        $this->info("Submissions CSV generated: {$timestampedFilename}");
        $this->info("Latest CSV available as: {$latestFilename}");

        \Log::info("GenCC Release: Submissions CSV generated", [
            'timestamped_file' => $timestampedFilename,
            'latest_file' => $latestFilename,
            'submission_count' => $submissions->count(),
        ]);
    }

    /**
     * Generate xlsx, xls, and tsv versions of the submissions export.
     */
    protected function generateAdditionalFormats(string $exportsDir, string $csvFilename, array $headers, $submissions): void
    {
        $this->info("  Generating additional formats...");

        // Create a spreadsheet with the data
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Submissions');

        // Add headers in row 1
        $col = 1;
        foreach ($headers as $header) {
            $sheet->setCellValue([$col, 1], $header);
            $col++;
        }

        // Helper function to extract nested values
        $getNestedValue = function($data, $keys, $default = '') use (&$getNestedValue) {
            if ($data === null) return $default;
            foreach ($keys as $key) {
                if (is_object($data)) {
                    $data = $data->$key ?? null;
                } elseif (is_array($data)) {
                    $data = $data[$key] ?? null;
                } else {
                    return $default;
                }
                if ($data === null) return $default;
            }
            return $data;
        };

        // Add data rows starting at row 2
        $row = 2;
        foreach ($submissions as $submission) {
            $originalData = $submission->original_submission_data;

            // Extract submitted_as fields
            $submittedAsHgncId = $getNestedValue($originalData, ['gene', 'id']);
            $submittedAsHgncSymbol = $getNestedValue($originalData, ['gene', 'symbol']);
            $submittedAsDiseaseId = $getNestedValue($originalData, ['disease', 'id']);
            $submittedAsDiseaseName = $getNestedValue($originalData, ['disease', 'name']);
            $submittedAsMoiId = $getNestedValue($originalData, ['moi', 'id']);
            $submittedAsMoiName = $getNestedValue($originalData, ['moi', 'name']);
            $submittedAsSubmitterId = $getNestedValue($originalData, ['additional_information', 'submitter_curie']);
            $submittedAsSubmitterName = $getNestedValue($originalData, ['additional_information', 'submitter_title']);
            $submittedAsClassificationId = $getNestedValue($originalData, ['classification', 'id']);
            $submittedAsClassificationName = $getNestedValue($originalData, ['classification', 'name']);
            $submittedAsDate = $getNestedValue($originalData, ['report', 'display_date']);
            $submittedAsReportUrl = $getNestedValue($originalData, ['report', 'ext_url']);
            // Notes come from submission_data (current/editable) rather than original_submission_data
            $submittedAsNotes = $getNestedValue($submission->submission_data, ['notes', 'display']);
            $submittedAsCriteriaUrl = $getNestedValue($originalData, ['criteria', 'url']);
            $submittedAsSubmissionId = $getNestedValue($originalData, ['additional_information', 'submitted_as_submission_id']);

            $pmids = $submission->normalized_pmids
                ? str_replace(',', ', ', $submission->normalized_pmids)
                : '';
            $submittedRunDate = $submission->released_at?->format('Y-m-d') ?? '';

            $rowData = [
                $submission->sid,
                $submission->version_number ?? 1,
                $submission->gene->hgnc_id ?? '',
                $submission->gene->symbol ?? '',
                $submission->disease->curie ?? '',
                $submission->disease->name ?? '',
                $submission->originalDisease->curie ?? '',
                $submission->originalDisease->name ?? '',
                $submission->classification->curie ?? '',
                $submission->classification->name ?? '',
                $submission->inheritance->curie ?? '',
                $submission->inheritance->name ?? '',
                $submission->submitter->curie ?? '',
                $submission->submitter->name ?? '',
                $submittedAsHgncId,
                $submittedAsHgncSymbol,
                $submittedAsDiseaseId,
                $submittedAsDiseaseName,
                $submittedAsMoiId,
                $submittedAsMoiName,
                $submittedAsSubmitterId,
                $submittedAsSubmitterName,
                $submittedAsClassificationId,
                $submittedAsClassificationName,
                $submittedAsDate,
                $submittedAsReportUrl,
                $submittedAsNotes,
                $pmids,
                $submittedAsCriteriaUrl,
                $submittedAsSubmissionId,
                $submittedRunDate,
            ];

            $col = 1;
            foreach ($rowData as $value) {
                $sheet->setCellValue([$col, $row], $value ?? '');
                $col++;
            }
            $row++;
        }

        // Generate base filename without extension
        $baseFilename = preg_replace('/\.csv$/', '', $csvFilename);
        $latestBase = 'gencc-submissions';

        // Write XLSX (timestamped and latest)
        try {
            $xlsxWriter = new XlsxWriter($spreadsheet);

            $xlsxTimestamped = $exportsDir . '/' . $baseFilename . '.xlsx';
            $xlsxWriter->save($xlsxTimestamped);
            $this->uploadToGcs(File::get($xlsxTimestamped), "csv/{$baseFilename}.xlsx",
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsxTimestamped);

            $xlsxLatest = $exportsDir . '/' . $latestBase . '.xlsx';
            File::copy($xlsxTimestamped, $xlsxLatest);
            $this->uploadToGcs(File::get($xlsxLatest), "csv/{$latestBase}.xlsx",
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $xlsxLatest);

            $this->info("    XLSX: {$baseFilename}.xlsx");
        } catch (\Exception $e) {
            $this->warn("    Failed to generate XLSX: {$e->getMessage()}");
        }

        // Write XLS (timestamped and latest)
        try {
            $xlsWriter = new XlsWriter($spreadsheet);

            $xlsTimestamped = $exportsDir . '/' . $baseFilename . '.xls';
            $xlsWriter->save($xlsTimestamped);
            $this->uploadToGcs(File::get($xlsTimestamped), "csv/{$baseFilename}.xls",
                'application/vnd.ms-excel', $xlsTimestamped);

            $xlsLatest = $exportsDir . '/' . $latestBase . '.xls';
            File::copy($xlsTimestamped, $xlsLatest);
            $this->uploadToGcs(File::get($xlsLatest), "csv/{$latestBase}.xls",
                'application/vnd.ms-excel', $xlsLatest);

            $this->info("    XLS: {$baseFilename}.xls");
        } catch (\Exception $e) {
            $this->warn("    Failed to generate XLS: {$e->getMessage()}");
        }

        // Write TSV (timestamped and latest)
        try {
            $tsvContent = $this->arrayToTsvLine($headers);
            foreach ($submissions as $submission) {
                $originalData = $submission->original_submission_data;

                $submittedAsHgncId = $getNestedValue($originalData, ['gene', 'id']);
                $submittedAsHgncSymbol = $getNestedValue($originalData, ['gene', 'symbol']);
                $submittedAsDiseaseId = $getNestedValue($originalData, ['disease', 'id']);
                $submittedAsDiseaseName = $getNestedValue($originalData, ['disease', 'name']);
                $submittedAsMoiId = $getNestedValue($originalData, ['moi', 'id']);
                $submittedAsMoiName = $getNestedValue($originalData, ['moi', 'name']);
                $submittedAsSubmitterId = $getNestedValue($originalData, ['additional_information', 'submitter_curie']);
                $submittedAsSubmitterName = $getNestedValue($originalData, ['additional_information', 'submitter_title']);
                $submittedAsClassificationId = $getNestedValue($originalData, ['classification', 'id']);
                $submittedAsClassificationName = $getNestedValue($originalData, ['classification', 'name']);
                $submittedAsDate = $getNestedValue($originalData, ['report', 'display_date']);
                $submittedAsReportUrl = $getNestedValue($originalData, ['report', 'ext_url']);
                // Notes come from submission_data (current/editable) rather than original_submission_data
                $submittedAsNotes = $getNestedValue($submission->submission_data, ['notes', 'display']);
                $submittedAsCriteriaUrl = $getNestedValue($originalData, ['criteria', 'url']);
                $submittedAsSubmissionId = $getNestedValue($originalData, ['additional_information', 'submitted_as_submission_id']);

                $pmids = $submission->normalized_pmids
                    ? str_replace(',', ', ', $submission->normalized_pmids)
                    : '';
                $submittedRunDate = $submission->released_at?->format('Y-m-d') ?? '';

                $rowData = [
                    $submission->sid,
                    $submission->version_number ?? 1,
                    $submission->gene->hgnc_id ?? '',
                    $submission->gene->symbol ?? '',
                    $submission->disease->curie ?? '',
                    $submission->disease->name ?? '',
                    $submission->originalDisease->curie ?? '',
                    $submission->originalDisease->name ?? '',
                    $submission->classification->curie ?? '',
                    $submission->classification->name ?? '',
                    $submission->inheritance->curie ?? '',
                    $submission->inheritance->name ?? '',
                    $submission->submitter->curie ?? '',
                    $submission->submitter->name ?? '',
                    $submittedAsHgncId,
                    $submittedAsHgncSymbol,
                    $submittedAsDiseaseId,
                    $submittedAsDiseaseName,
                    $submittedAsMoiId,
                    $submittedAsMoiName,
                    $submittedAsSubmitterId,
                    $submittedAsSubmitterName,
                    $submittedAsClassificationId,
                    $submittedAsClassificationName,
                    $submittedAsDate,
                    $submittedAsReportUrl,
                    $submittedAsNotes,
                    $pmids,
                    $submittedAsCriteriaUrl,
                    $submittedAsSubmissionId,
                    $submittedRunDate,
                ];
                $tsvContent .= $this->arrayToTsvLine($rowData);
            }

            $tsvTimestamped = $exportsDir . '/' . $baseFilename . '.tsv';
            File::put($tsvTimestamped, $tsvContent);
            $this->uploadToGcs($tsvContent, "csv/{$baseFilename}.tsv", 'text/tab-separated-values', $tsvTimestamped);

            $tsvLatest = $exportsDir . '/' . $latestBase . '.tsv';
            File::put($tsvLatest, $tsvContent);
            $this->uploadToGcs($tsvContent, "csv/{$latestBase}.tsv", 'text/tab-separated-values', $tsvLatest);

            $this->info("    TSV: {$baseFilename}.tsv");
        } catch (\Exception $e) {
            $this->warn("    Failed to generate TSV: {$e->getMessage()}");
        }

        $this->info("  Additional formats complete");
    }

    /**
     * Convert an array to a TSV line.
     */
    protected function arrayToTsvLine(array $fields): string
    {
        $escaped = array_map(function ($field) {
            if ($field === null) {
                return '';
            }
            $field = (string) $field;
            // Escape tabs and newlines within fields
            $field = str_replace(["\t", "\n", "\r"], [' ', ' ', ' '], $field);
            return $field;
        }, $fields);

        return implode("\t", $escaped) . "\n";
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
