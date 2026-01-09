<?php

namespace App\Http\Controllers;
use Inertia\Inertia;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

use Auth;

use App\Models\Job;
use App\Models\Submission;

use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 *
 * @category   Controller
 * @package    GenCC
 * @author     P. Weller <pweller1@geisinger.edu>
 * @copyright  2024 Geisinger, GenCC, ClinGen
 * @license    
 * @version    Release: @package_version@
 * @link       
 * @see        
 * @since      Class available since Release 1.0.0
 * 
 * DashboardController supplies the dashboard metrics and information data to Inertia/Vue.  
 *
 * */
class DashboardController extends Controller
{
    /**
     * Count submissions by status type for a given job.
     * Returns counts for new, republish, unpublish, and optionally errors.
     *
     * @param  \App\Models\Job  $job
     * @param  bool  $includeErrors  Whether to count errors per status type
     * @return array
     */
    private function countSubmissionsByStatus(Job $job, bool $includeErrors = false): array
    {
        $counts = [
            'new' => $job->submissions()
                ->whereIn('status', [Submission::STATUS_DRAFT_NEW, Submission::STATUS_SUBMITTED_NEW])
                ->count(),
            'republish' => $job->submissions()
                ->whereIn('status', [Submission::STATUS_DRAFT_REPUBLISH, Submission::STATUS_SUBMITTED_REPUBLISH])
                ->count(),
            'unpublish' => $job->submissions()
                ->whereIn('status', [Submission::STATUS_DRAFT_UNPUBLISH, Submission::STATUS_SUBMITTED_UNPUBLISH])
                ->count(),
        ];

        if ($includeErrors) {
            $counts['errors'] = $job->submissions()
                ->whereNotNull('submission_errors')
                ->count();
            $counts['new_errors'] = $job->submissions()
                ->whereIn('status', [Submission::STATUS_DRAFT_NEW, Submission::STATUS_SUBMITTED_NEW])
                ->whereNotNull('submission_errors')
                ->count();
            $counts['republish_errors'] = $job->submissions()
                ->whereIn('status', [Submission::STATUS_DRAFT_REPUBLISH, Submission::STATUS_SUBMITTED_REPUBLISH])
                ->whereNotNull('submission_errors')
                ->count();
            $counts['unpublish_errors'] = $job->submissions()
                ->whereIn('status', [Submission::STATUS_DRAFT_UNPUBLISH, Submission::STATUS_SUBMITTED_UNPUBLISH])
                ->whereNotNull('submission_errors')
                ->count();
        }

        return $counts;
    }

    /**
     * Gather all the metrics and statistics for the dashboard page.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Determine which submitter's data to show
        $submitter_id = $user->submitter_id;

        // For GenCC Administrator, check if they've selected a different submitter
        if ($user->isGenccAdmin() && $request->session()->has('selected_submitter_id')) {
            $submitter_id = $request->session()->get('selected_submitter_id');
        }

        // If GenCC Admin with no selected submitter, show system-wide stats
        if ($user->isGenccAdmin() && !$request->session()->has('selected_submitter_id')) {
            $submitter_id = null;
        }

        $count = 0;

        // V2: Active jobs - submitted jobs that are pending publish
        if ($submitter_id === null) {
            // System-wide active jobs (for GenCC Admin only)
            $jobs = Job::with('submissions')
                        ->where('status', Job::STATUS_SUBMITTED)
                        ->get();
        } else {
            // Submitter-specific active jobs
            $jobs = Job::where('submitter_id', $submitter_id)
                        ->with('submissions')
                        ->where('status', Job::STATUS_SUBMITTED)
                        ->get();
        }

        $job_processing_count = 0;
        $submission_processing_count = 0;
        $submission_new_count = 0;
        $submission_republish_count = 0;
        $submission_unpublish_count = 0;
        $job_error_count = 0;
        $submission_error_count = 0;

        foreach ($jobs as $item) {
            // V2: Count jobs with errors using computed property
            if ($item->has_errors) {
                $job_error_count++;
            } else {
                $job_processing_count++;
            }

            $count += $item->submissions()->count();

            $submission_error_count += $item->submissions->filter(fn($s) => $s->has_errors)->count();

            // Count submissions in draft/submitted states (not published, not unpublished)
            $submission_processing_count += $item->submissions->filter(fn($s) =>
                in_array($s->status, [
                    Submission::STATUS_DRAFT_NEW,
                    Submission::STATUS_DRAFT_REPUBLISH,
                    Submission::STATUS_DRAFT_UNPUBLISH,
                    Submission::STATUS_SUBMITTED_NEW,
                    Submission::STATUS_SUBMITTED_REPUBLISH,
                    Submission::STATUS_SUBMITTED_UNPUBLISH,
                ])
            )->count();

            // V2: Count by status for submitted jobs using shared helper method
            if ($item->status === Job::STATUS_SUBMITTED) {
                $statusCounts = $this->countSubmissionsByStatus($item);
                $submission_new_count += $statusCounts['new'];
                $submission_republish_count += $statusCounts['republish'];
                $submission_unpublish_count += $statusCounts['unpublish'];
            }
        }

        // V2: Processed jobs - jobs that have been fully processed
        if ($submitter_id === null) {
            // System-wide processed jobs (for GenCC Admin only)
            $processedJobs = Job::where('status', Job::STATUS_PROCESSED)->get();
        } else {
            // Submitter-specific processed jobs
            $processedJobs = Job::where('submitter_id', $submitter_id)
                        ->where('status', Job::STATUS_PROCESSED)
                        ->get();
        }

        $job_processed_count = $processedJobs->count();

        // V2: Get unique SGC IDs and their current PUBLIC state based on processed jobs
        // Use the processed_submission_ids field on jobs to track what was originally processed
        $sgcStates = [];

        // Iterate through all processed jobs and extract SGC IDs with their actions
        foreach ($processedJobs as $job) {
            if (!$job->processed_submission_ids || !is_array($job->processed_submission_ids)) {
                continue;
            }

            // Each entry in processed_submission_ids has 'sid' and 'action' (publish/republish/unpublish/error)
            foreach ($job->processed_submission_ids as $entry) {
                $sgcId = $entry['sid'] ?? null;
                $action = $entry['action'] ?? null;

                if (!$sgcId || !$action) {
                    continue;
                }

                // Skip errors - they don't represent a public state
                if ($action === 'error') {
                    continue;
                }

                // Determine the public state based on action
                // 'published' and 'republished' → STATUS_V2_PUBLISHED
                // 'unpublished' → STATUS_V2_UNPUBLISHED
                if ($action === 'published' || $action === 'republished') {
                    $publicState = Submission::STATUS_PUBLISHED;
                } elseif ($action === 'unpublished') {
                    $publicState = Submission::STATUS_UNPUBLISHED;
                } else {
                    continue;
                }

                // Track the most recent state for this SGC ID (use job ID for ordering)
                // Job IDs are sequential and represent the true processing order
                $existingJobId = isset($sgcStates[$sgcId]['job_id']) ? $sgcStates[$sgcId]['job_id'] : null;

                if (!isset($sgcStates[$sgcId]) || $job->id > $existingJobId) {
                    $sgcStates[$sgcId] = [
                        'status' => $publicState,
                        'job_id' => $job->id
                    ];
                }
            }
        }

        // Count published vs unpublished based on most recent processed state
        $submission_published_count = 0;
        $submission_unpublished_count = 0;

        foreach ($sgcStates as $sgcId => $state) {
            if ($state['status'] === Submission::STATUS_PUBLISHED) {
                $submission_published_count++;
            } elseif ($state['status'] === Submission::STATUS_UNPUBLISHED) {
                $submission_unpublished_count++;
            }
        }

        // Get unprocessed job (draft or submitted) - there can only be one at a time
        // For admins with no selected submitter, don't show any active job
        if ($submitter_id === null) {
            // No unprocessed job for admin when no submitter is selected
            $unprocessedJob = null;
        } else {
            // Submitter-specific unprocessed job
            $unprocessedJob = Job::where('submitter_id', $submitter_id)
                ->whereIn('status', [Job::STATUS_DRAFT, Job::STATUS_SUBMITTED])
                ->with('submissions')
                ->first();
        }

        // Initialize unprocessed job data
        $unprocessed_job_status = null;
        $unprocessed_job_date = null;
        $unprocessed_job_slug = null;
        $unprocessed_job_ident = null;
        $unprocessed_job_is_publishing = false;
        $unprocessed_job_is_processing = false;
        $unprocessed_new_count = 0;
        $unprocessed_republish_count = 0;
        $unprocessed_unpublish_count = 0;
        $unprocessed_error_count = 0;
        $unprocessed_new_error_count = 0;
        $unprocessed_republish_error_count = 0;
        $unprocessed_unpublish_error_count = 0;

        if ($unprocessedJob) {
            $unprocessed_job_status = $unprocessedJob->status;
            $unprocessed_job_slug = $unprocessedJob->slug;
            $unprocessed_job_ident = $unprocessedJob->ident;
            $unprocessed_job_is_publishing = $unprocessedJob->is_publishing;
            $unprocessed_job_is_processing = $unprocessedJob->is_processing;

            // Use created_at for draft, updated_at for submitted
            if ($unprocessedJob->status === Job::STATUS_DRAFT) {
                $unprocessed_job_date = $unprocessedJob->created_at->format('Y-m-d');
            } else {
                $unprocessed_job_date = $unprocessedJob->updated_at->format('Y-m-d');
            }

            // Count submissions by status type using shared helper method
            $includeErrors = $unprocessedJob->status === Job::STATUS_DRAFT;
            $statusCounts = $this->countSubmissionsByStatus($unprocessedJob, $includeErrors);

            $unprocessed_new_count = $statusCounts['new'];
            $unprocessed_republish_count = $statusCounts['republish'];
            $unprocessed_unpublish_count = $statusCounts['unpublish'];

            // Error counts (only relevant for draft jobs)
            if ($includeErrors) {
                $unprocessed_error_count = $statusCounts['errors'];
                $unprocessed_new_error_count = $statusCounts['new_errors'];
                $unprocessed_republish_error_count = $statusCounts['republish_errors'];
                $unprocessed_unpublish_error_count = $statusCounts['unpublish_errors'];
            }
        }

        // determine token expiration date
        $expire_date = $user->api_token_renewed_at->addYears(2);

        // Get submitter CURIE for frontend (for ClinGen sync button)
        $submitter_curie = null;
        if ($submitter_id !== null) {
            $submitter = \App\Models\Submitter::find($submitter_id);
            if ($submitter) {
                $submitter_curie = $submitter->curie;
            }
        }

        // ========================================================================
        // SECTION 1: SUBMISSIONS RELEASED (is_live = true)
        // Only live versions - broken down by first version, republish, hidden
        // ========================================================================
        $releasedQuery = Submission::query()->where('is_live', true);
        if ($submitter_id !== null) {
            $releasedQuery->where('submitter_id', $submitter_id);
        }

        // First version released: version_number = 1 AND status = published AND is_live = true
        $released_first_version_count = (clone $releasedQuery)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->where('version_number', 1)
            ->count();

        // Republish released: version_number > 1 AND status = published AND is_live = true
        $released_republish_count = (clone $releasedQuery)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->where('version_number', '>', 1)
            ->count();

        // Hidden/Unpublished released: status = unpublished AND is_live = true
        $released_unpublish_count = (clone $releasedQuery)
            ->where('status', Submission::STATUS_UNPUBLISHED)
            ->count();

        // Total released = first_version + republish (visible in gencc-search) + hidden
        $released_total = $released_first_version_count + $released_republish_count + $released_unpublish_count;

        // ========================================================================
        // SECTION 2: SUBMISSIONS AWAITING RELEASE (draft/submitted states)
        // Broken down by first version, republish, unpublish
        // ========================================================================
        $awaitingQuery = Submission::query();
        if ($submitter_id !== null) {
            $awaitingQuery->where('submitter_id', $submitter_id);
        }

        // First version awaiting: draft_new or submitted_new
        $awaiting_first_version_count = (clone $awaitingQuery)
            ->whereIn('status', [Submission::STATUS_DRAFT_NEW, Submission::STATUS_SUBMITTED_NEW])
            ->count();

        // Republish awaiting: draft_republish or submitted_republish
        $awaiting_republish_count = (clone $awaitingQuery)
            ->whereIn('status', [Submission::STATUS_DRAFT_REPUBLISH, Submission::STATUS_SUBMITTED_REPUBLISH])
            ->count();

        // Unpublish awaiting: draft_unpublish or submitted_unpublish
        $awaiting_unpublish_count = (clone $awaitingQuery)
            ->whereIn('status', [Submission::STATUS_DRAFT_UNPUBLISH, Submission::STATUS_SUBMITTED_UNPUBLISH])
            ->count();

        $awaiting_total = $awaiting_first_version_count + $awaiting_republish_count + $awaiting_unpublish_count;

        // ========================================================================
        // SECTION 3: SUBMISSIONS ARCHIVED (released_at NOT NULL AND is_live = false)
        // Only count max version per SGC ID to avoid double counting
        // Breakdown: if max version is unpublished -> unpublished, if v1 -> first version, else republish
        // Also provide total counts (in parentheses) for republish and unpublish categories
        // ========================================================================

        // Get all archived submissions (released but not live)
        $archivedQuery = Submission::query()
            ->whereNotNull('released_at')
            ->where('is_live', false);
        if ($submitter_id !== null) {
            $archivedQuery->where('submitter_id', $submitter_id);
        }

        // Get the max version for each SGC ID among archived submissions
        // Using a subquery to find max version_number per sid
        $maxVersionSubquery = \DB::table('submissions as s2')
            ->select('s2.sid', \DB::raw('MAX(s2.version_number) as max_version'))
            ->whereNotNull('s2.released_at')
            ->where('s2.is_live', false)
            ->whereNull('s2.deleted_at');
        if ($submitter_id !== null) {
            $maxVersionSubquery->where('s2.submitter_id', $submitter_id);
        }
        $maxVersionSubquery->groupBy('s2.sid');

        // Join to get only the max version records for counting
        $archivedMaxVersionQuery = Submission::query()
            ->whereNotNull('released_at')
            ->where('is_live', false)
            ->joinSub($maxVersionSubquery, 'max_versions', function ($join) {
                $join->on('submissions.sid', '=', 'max_versions.sid')
                     ->on('submissions.version_number', '=', 'max_versions.max_version');
            });
        if ($submitter_id !== null) {
            $archivedMaxVersionQuery->where('submissions.submitter_id', $submitter_id);
        }

        // Archived first version (unique): max version is v1 and status is published
        $archived_first_version_unique = (clone $archivedMaxVersionQuery)
            ->where('submissions.status', Submission::STATUS_PUBLISHED)
            ->where('submissions.version_number', 1)
            ->count();

        // Archived republish (unique): max version > 1 and status is published
        $archived_republish_unique = (clone $archivedMaxVersionQuery)
            ->where('submissions.status', Submission::STATUS_PUBLISHED)
            ->where('submissions.version_number', '>', 1)
            ->count();

        // Archived unpublish (unique): max version status is unpublished
        $archived_unpublish_unique = (clone $archivedMaxVersionQuery)
            ->where('submissions.status', Submission::STATUS_UNPUBLISHED)
            ->count();

        // Total counts (all archived versions, not just max)
        $archived_first_version_total = (clone $archivedQuery)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->where('version_number', 1)
            ->count();

        $archived_republish_total = (clone $archivedQuery)
            ->where('status', Submission::STATUS_PUBLISHED)
            ->where('version_number', '>', 1)
            ->count();

        $archived_unpublish_total = (clone $archivedQuery)
            ->where('status', Submission::STATUS_UNPUBLISHED)
            ->count();

        // Unique count = number of unique SGC IDs in archived state
        $archived_unique_total = $archived_first_version_unique + $archived_republish_unique + $archived_unpublish_unique;

        // Total archived versions
        $archived_total = $archived_first_version_total + $archived_republish_total + $archived_unpublish_total;

        // Total unique SGC IDs (for reference/header)
        $versioningQuery = Submission::query();
        if ($submitter_id !== null) {
            $versioningQuery->where('submitter_id', $submitter_id);
        }
        $total_unique_sids = (clone $versioningQuery)->distinct('sid')->count('sid');

        // Calculate classification distribution from processed jobs
        // This counts classifications from the last published version of each SGC ID
        // NOT from current submission state (which may be in draft/republish)
        $classificationOrder = [2, 3, 4, 5, 6, 7, 8, 9, 10]; // Definitive through NKDR
        $classifications = array_fill(0, count($classificationOrder), 0);

        // Track the latest classification for each SGC ID across all processed jobs
        $latestClassifications = [];

        // Sort jobs by released_at to process chronologically (oldest to newest)
        $sortedProcessedJobs = $processedJobs->sortBy(function($job) {
            return $job->released_at ? $job->released_at->timestamp : $job->id;
        });

        foreach ($sortedProcessedJobs as $job) {
            if ($job->processed_submission_ids && is_array($job->processed_submission_ids)) {
                foreach ($job->processed_submission_ids as $entry) {
                    $sid = $entry['sid'] ?? null;
                    $action = $entry['action'] ?? null;
                    $classification_id = $entry['classification_id'] ?? null;

                    if (!$sid) continue;

                    // Track or update classification for this SGC ID based on action
                    if ($action === 'published' || $action === 'republished') {
                        // Update/store the classification for this SGC ID
                        if ($classification_id) {
                            $latestClassifications[$sid] = $classification_id;
                        }
                    } elseif ($action === 'unpublished') {
                        // Remove this SGC ID from published classifications
                        unset($latestClassifications[$sid]);
                    }
                }
            }
        }

        // Count classifications from the latest state of each SGC ID
        foreach ($latestClassifications as $classification_id) {
            $index = array_search($classification_id, $classificationOrder);
            if ($index !== false) {
                $classifications[$index]++;
            }
        }

        // Get the last 5 processed jobs chronologically by released_at date
        // Show each job with parallel bars for new/republished/unpublished counts
        $jobLabels = [];
        $submissions_new = [];
        $submissions_republished = [];
        $submissions_unpublished_chart = [];

        // Sort jobs by released_at (or ID as fallback) and take the last 5
        $sortedJobs = $processedJobs->sortBy(function($job) {
            return $job->released_at ? $job->released_at->timestamp : $job->id;
        })->values();

        // Take the last 5 jobs (most recent)
        $recentJobs = $sortedJobs->slice(-5);

        foreach ($recentJobs as $job) {
            // Create job label: Job ID + Date (e.g., "J-100002\n2025-06-05")
            $dateLabel = $job->released_at ? $job->released_at->format('Y-m-d') : 'N/A';
            $jobLabels[] = $job->slug . "\n" . $dateLabel;

            // Count submissions by action type for this job
            $newCount = 0;
            $republishedCount = 0;
            $unpublishedCount = 0;

            if ($job->processed_submission_ids && is_array($job->processed_submission_ids)) {
                foreach ($job->processed_submission_ids as $entry) {
                    $action = $entry['action'] ?? null;

                    if (!$action || $action === 'error') {
                        continue;
                    }

                    // Count all action types
                    if ($action === 'published') {
                        $newCount++;
                    } elseif ($action === 'republished') {
                        $republishedCount++;
                    } elseif ($action === 'unpublished') {
                        $unpublishedCount++;
                    }
                }
            }

            $submissions_new[] = $newCount;
            $submissions_republished[] = $republishedCount;
            $submissions_unpublished_chart[] = $unpublishedCount;
        }

        return Inertia::render('Dashboard', [
            'total_jobs_processing' => $job_processing_count,
            'total_submissions_processing' => $submission_processing_count,
            'active_new_count' => $submission_new_count,
            'active_republish_count' => $submission_republish_count,
            'active_unpublish_count' => $submission_unpublish_count,
            'total_jobs_errors' => $job_error_count,
            'total_submissions_errors' => $submission_error_count,
            'total_jobs_completed' => $job_processed_count,
            'total_submissions_published' => $submission_published_count,
            'total_submissions_unpublished' => $submission_unpublished_count,
            'token_expire_date' => $expire_date->format('Y-m-d'),
            'token_days' => Carbon::now()->diffInDays($expire_date),
            'unprocessed_job_status' => $unprocessed_job_status,
            'unprocessed_job_date' => $unprocessed_job_date,
            'unprocessed_job_slug' => $unprocessed_job_slug,
            'unprocessed_job_ident' => $unprocessed_job_ident,
            'unprocessed_job_is_publishing' => $unprocessed_job_is_publishing,
            'unprocessed_job_is_processing' => $unprocessed_job_is_processing,
            'unprocessed_new_count' => $unprocessed_new_count,
            'unprocessed_republish_count' => $unprocessed_republish_count,
            'unprocessed_unpublish_count' => $unprocessed_unpublish_count,
            'unprocessed_error_count' => $unprocessed_error_count,
            'unprocessed_new_error_count' => $unprocessed_new_error_count,
            'unprocessed_republish_error_count' => $unprocessed_republish_error_count,
            'unprocessed_unpublish_error_count' => $unprocessed_unpublish_error_count,
            'has_submitter' => $submitter_id !== null,
            'submitter_curie' => $submitter_curie,
            'job_labels' => $jobLabels,
            'classifications' => $classifications,
            'submissions_new' => $submissions_new,
            'submissions_republished' => $submissions_republished,
            'submissions_unpublished_chart' => $submissions_unpublished_chart,
            'total_unique_sids' => $total_unique_sids,
            // Section 1: Submissions Released (is_live = true)
            'released_first_version_count' => $released_first_version_count,
            'released_republish_count' => $released_republish_count,
            'released_unpublish_count' => $released_unpublish_count,
            'released_total' => $released_total,
            // Section 2: Submissions Awaiting Release (draft/submitted states)
            'awaiting_first_version_count' => $awaiting_first_version_count,
            'awaiting_republish_count' => $awaiting_republish_count,
            'awaiting_unpublish_count' => $awaiting_unpublish_count,
            'awaiting_total' => $awaiting_total,
            // Section 3: Submissions Archived (released but not live)
            'archived_first_version_unique' => $archived_first_version_unique,
            'archived_republish_unique' => $archived_republish_unique,
            'archived_unpublish_unique' => $archived_unpublish_unique,
            'archived_first_version_total' => $archived_first_version_total,
            'archived_republish_total' => $archived_republish_total,
            'archived_unpublish_total' => $archived_unpublish_total,
            'archived_unique_total' => $archived_unique_total,
            'archived_total' => $archived_total,
        ]);
    }

    /**
     * Run ClinGen GCI Sync pipeline and return zip file for download
     */
    public function clingenSync()
    {
        try {
            // Run the artisan command
            Artisan::call('clingen:sync');

            // Get the output which contains the zip filename
            $output = Artisan::output();
            $lines = explode("\n", trim($output));
            $zipFileName = end($lines); // Last line is the filename

            // Check if the zip file exists
            $zipPath = storage_path('app/public/' . $zipFileName);

            if (!file_exists($zipPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Zip file was not created'
                ], 500);
            }

            // Return the download URL
            return response()->json([
                'success' => true,
                'download_url' => '/storage/' . $zipFileName,
                'filename' => $zipFileName
            ]);

        } catch (\Exception $e) {
            \Log::error('ClinGen sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
