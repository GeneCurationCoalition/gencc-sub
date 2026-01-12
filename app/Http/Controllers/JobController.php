<?php

namespace App\Http\Controllers;
use Inertia\Inertia;

use Illuminate\Http\Request;

use Auth;
use App\Models\Job;
use App\Models\Submission;

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
 * JobsController supplies the job data to Inertia/Vue.
 *
 * */
class JobController extends Controller
{

    /**
     * List all the submitter jobs for the authenticated user, along with the
     * list of which ones are marked as favorites by this user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        // get all the jobs for the submitter associated with the user
        $jobs = $this->getEffectiveSubmitter($request)->jobs()
                            ->with('user:id,name')
                            ->with(['documents' => function ($query) {
                                $query->select('id', 'job_id', 'upload_state');
                            }])
                            ->withCount(['submissions'])
                            ->withCount(['submissions as published_or_staged_count' => function ($query) {
                                $query->where('status', Submission::STATUS_PUBLISHED)
                                      ->orWhereNotNull('publish_date');
                            }])
                            ->withCount(['submissions as error_count' => function ($query) {
                                $query->whereNotNull('submission_errors');
                            }])
                            ->withCount(['submissions as draft_new_count' => function ($query) {
                                $query->where('status', Submission::STATUS_DRAFT_NEW);
                            }])
                            ->withCount(['submissions as draft_republish_count' => function ($query) {
                                $query->where('status', Submission::STATUS_DRAFT_REPUBLISH);
                            }])
                            ->withCount(['submissions as draft_unpublish_count' => function ($query) {
                                $query->where('status', Submission::STATUS_DRAFT_UNPUBLISH);
                            }])
                            ->get();

        // Check if job creation is allowed
        // Rule: Only one draft job allowed at a time, and no draft jobs while submitted jobs exist
        $hasDraftJob = $jobs->contains(function ($job) {
            return $job->status === Job::STATUS_DRAFT;
        });

        $hasSubmittedJob = $jobs->contains(function ($job) {
            return $job->status === Job::STATUS_SUBMITTED;
        });

        $canCreateJob = !$hasDraftJob && !$hasSubmittedJob;

        // Check if there's a draft job without a document (can upload to existing)
        $canUploadToExisting = false;
        if ($hasDraftJob) {
            $draftJob = $jobs->first(function ($job) {
                return $job->status === Job::STATUS_DRAFT;
            });
            if ($draftJob && $draftJob->documents->isEmpty()) {
                $canUploadToExisting = true;
            }
        }

        // Determine the reason why job creation is blocked
        $blockReason = null;
        if (!$canCreateJob) {
            if ($hasSubmittedJob) {
                $blockReason = 'submitted';
            } elseif ($hasDraftJob) {
                $blockReason = 'draft_exists';
            }
        }

        return Inertia::render('Jobs', [
            'jobs' => $jobs,
            'favorites' => Auth::user()->preferences->job_favorites ?? [],
            'canCreateJob' => $canCreateJob,
            'canUploadToExisting' => $canUploadToExisting,
            'blockReason' => $blockReason
        ]);
    }


    /**
     * Show a particular job along wiih its submissions for the authenticated user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        $job = $this->getEffectiveSubmitterQuery($request, 'jobs')->select('id', 'ident', 'slug', 'friendly', 'type', 'user_id', 'submitter_id', 'status', 'processed_submission_ids', 'is_publishing', 'is_processing', 'created_at', 'submitted_at', 'released_at')
                        ->with('submitter:id,name,curie')->with('user:id,name,email')
                        ->with(['documents' => function ($query) {
                            // Include all documents, including those with partial upload warnings and upload state
                            $query->select('id', 'ident', 'job_id', 'file_name', 'extension', 'size', 'upload_state', 'processed_submissions', 'total_submissions', 'processing_errors', 'created_at');
                        }])
                        ->withCount('documents')
                        ->where('ident', $id)->first();

        if (!$job) {
            abort(404, 'Job not found');
        }

        $submissions = $job->submissions()->forListing()->get();

        // Check if THIS specific job is submitted OR if there are ANY submitted jobs for this submitter
        // Used to disable bulk actions/checkboxes to prevent:
        // 1. Editing submissions in a submitted job
        // 2. Creating conflicting draft jobs when another submitted job exists
        $isCurrentJobSubmitted = $job->status === Job::STATUS_SUBMITTED;
        $hasOtherSubmittedJob = $this->getEffectiveSubmitter($request)
            ->jobs()
            ->where('status', Job::STATUS_SUBMITTED)
            ->exists();

        $hasSubmittedJob = $isCurrentJobSubmitted || $hasOtherSubmittedJob;

        \Log::info("Job {$job->slug} status: {$job->status}, isCurrentJobSubmitted: " . ($isCurrentJobSubmitted ? 'true' : 'false') . ", hasOtherSubmittedJob: " . ($hasOtherSubmittedJob ? 'true' : 'false') . ", hasSubmittedJob: " . ($hasSubmittedJob ? 'true' : 'false'));

        return Inertia::render('Job', [
            'job' => $job,
            'submissions' => $submissions,
            'errors' => false,
            'favorites' => Auth::user()->preferences->sub_favorites ?? [],
            'hasSubmittedJob' => $hasSubmittedJob
        ]);
    }

    /**
     * Get upload progress for a job
     * Used for polling during async file upload
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $id  Job identifier
     * @return \Illuminate\Http\Response
     */
    public function uploadProgress(Request $request, $id)
    {
        $job = $this->getEffectiveSubmitterQuery($request, 'jobs')
            ->with('documents')
            ->where('ident', $id)
            ->first();

        if (!$job) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        $document = $job->documents->first();

        if (!$document) {
            return response()->json(['has_document' => false], 200);
        }

        return response()->json([
            'has_document' => true,
            'upload_state' => $document->upload_state,
            'processed_submissions' => $document->processed_submissions,
            'total_submissions' => $document->total_submissions,
            'progress_percent' => $document->total_submissions > 0
                ? round(($document->processed_submissions / $document->total_submissions) * 100)
                : 0,
            'is_processing' => $job->is_processing,
            'processing_errors' => $document->processing_errors,
        ], 200);
    }
}
