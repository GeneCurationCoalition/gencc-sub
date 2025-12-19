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

        $jobs->each(function ($item) use (&$count, &$job_error_count, &$submission_error_count, &$job_processing_count, &$submission_processing_count, &$submission_new_count, &$submission_republish_count, &$submission_unpublish_count){

            // V2: Count jobs with errors using computed property
            if ($item->has_errors)
                $job_error_count++;
            else
                $job_processing_count++;

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

            // V2: Count by origin_state for submitted jobs
            if ($item->status === Job::STATUS_SUBMITTED) {
                $submission_new_count += $item->submissions()->whereNull('origin_state')->count();
                $submission_republish_count += $item->submissions()->where('origin_state', 'published')->count();
                $submission_unpublish_count += $item->submissions()->where('origin_state', 'unpublished')->count();
            }

        });

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

            // Count submissions by origin state
            // origin_state is null for new submissions, 'published' for republish, 'unpublished' for unpublish
            $unprocessed_new_count = $unprocessedJob->submissions()
                ->whereNull('origin_state')
                ->count();

            $unprocessed_republish_count = $unprocessedJob->submissions()
                ->where('origin_state', 'published')
                ->count();

            $unprocessed_unpublish_count = $unprocessedJob->submissions()
                ->where('origin_state', 'unpublished')
                ->count();

            // Count errors (only relevant for draft jobs)
            // Submissions have errors if submission_errors field is not null
            if ($unprocessedJob->status === Job::STATUS_DRAFT) {
                $unprocessed_error_count = $unprocessedJob->submissions()
                    ->whereNotNull('submission_errors')
                    ->count();

                // Count errors per status type
                $unprocessed_new_error_count = $unprocessedJob->submissions()
                    ->whereNull('origin_state')
                    ->whereNotNull('submission_errors')
                    ->count();

                $unprocessed_republish_error_count = $unprocessedJob->submissions()
                    ->where('origin_state', 'published')
                    ->whereNotNull('submission_errors')
                    ->count();

                $unprocessed_unpublish_error_count = $unprocessedJob->submissions()
                    ->where('origin_state', 'unpublished')
                    ->whereNotNull('submission_errors')
                    ->count();
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

        // Calculate classification distribution from processed jobs
        // This counts classifications from the last published version of each SGC ID
        // NOT from current submission state (which may be in draft/republish)
        $classificationOrder = [2, 3, 4, 5, 6, 7, 8, 9, 10]; // Definitive through NKDR
        $classifications = array_fill(0, count($classificationOrder), 0);

        // Track the latest classification for each SGC ID across all processed jobs
        $latestClassifications = [];

        // Sort jobs by published_at to process chronologically (oldest to newest)
        $sortedProcessedJobs = $processedJobs->sortBy(function($job) {
            return $job->published_at ? $job->published_at->timestamp : $job->id;
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

        // Get the last 5 processed jobs chronologically by published_at date
        // Show each job with parallel bars for new/republished/unpublished counts
        $jobLabels = [];
        $submissions_new = [];
        $submissions_republished = [];
        $submissions_unpublished_chart = [];

        // Sort jobs by published_at (or ID as fallback) and take the last 5
        $sortedJobs = $processedJobs->sortBy(function($job) {
            return $job->published_at ? $job->published_at->timestamp : $job->id;
        })->values();

        // Take the last 5 jobs (most recent)
        $recentJobs = $sortedJobs->slice(-5);

        foreach ($recentJobs as $job) {
            // Create job label: Job ID + Date (e.g., "J-100002\n2025-06-05")
            $dateLabel = $job->published_at ? $job->published_at->format('Y-m-d') : 'N/A';
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
            'submissions_unpublished_chart' => $submissions_unpublished_chart
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
