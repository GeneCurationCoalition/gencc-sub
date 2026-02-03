<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Requests\JobRequest;

use App\Models\Disease;
use App\Models\Inheritance;
use App\Models\Gene;
use App\Models\Classification;
use App\Models\Submission;
use App\Models\Document;

use Auth;
use Carbon\Carbon;

use App\Models\Job;
use App\Services\JobStateMachine;

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
 * API/JobController supplies the user data to Inertia/Vue.
 *
 * */
class JobController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        if ($user === null)
            return response()->json(['success' => 'false',
                    'status_code' => 3001,
                    'message' => 'Unauthorized'],
                    200);

        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

        $job = new Job(
            ['user_id' => $user->id,
             'submitter_id' => $effectiveSubmitterId,
             // created_at is auto-set by Laravel
             'status' => Job::STATUS_DRAFT ]
        );

        $job->save();

        return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => 'Job Created',
                'job' => [
                    'id' => $job->id,
                    'ident' => $job->ident,
                    'slug' => $job->slug
                ]],
                200);

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
        /*$disease = Disease::rosetta($id);

        if ($disease === null)
            return response()->json(['success' => 'false',
                'status_code' => 3001,
                'message' => 'Disease not found'],
                200);

        return $disease;*/
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(JobRequest $request, string $id)
    {
        /*$submission = Auth::user()->submissions()
                        ->where('sid', $id)->first();
                        
        if ($submission === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);
        
        switch ($request->input('type'))
        {
            case 'inheritance':
                $inheritance = Inheritance::curie($request->input('curie'))->first();

                if ($inheritance === null)
                    return response()->json(['success' => 'false',
                        'status_code' => 3001,
                        'message' => 'Inheritance not found'],
                        200);

                // update the submission disease
                $submission->inheritance_id = $inheritance->id;
                $bag = 'moi_curie_id';
                break;
            case 'classification':
                $classification = Classification::curie($request->input('curie'))->first();

                if ($classification === null)
                    return response()->json(['success' => 'false',
                        'status_code' => 3001,
                        'message' => 'Classification not found'],
                        200);

                // update the submission disease
                $submission->classification_id = $classification->id;
                $bag = 'classification_curie_id';
                break;
            case 'disease':
                $disease = Disease::rosetta($request->input('curie'));

                if ($disease === null)
                    return response()->json(['success' => 'false',
                        'status_code' => 3001,
                        'message' => 'Disease not found'],
                        200);

                // update the submission disease
                $submission->disease_id = $disease->id;
                $bag = 'disease_curie_id';
                break;
            case 'gene':
                $gene = Gene::hgnc_id($request->input('curie'))->first();

                if ($gene === null)
                    return response()->json(['success' => 'false',
                        'status_code' => 3001,
                        'message' => 'Gene not found'],
                        200);

                // update the submission gene
                $submission->gene_id = $gene->id;
                $bag = 'gene_hgnc_id';
                break;
            case 'publishdate':
                $submission->publish_date = Carbon::parse($request->input('curie'));
                $bag = 'publish_date';
                break;
        }
        
        // update the submission errors
        $eb = $submission->submission_errors;
        unset($eb->$bag);
        $submission->submission_errors =(empty((array) $eb) ? null : $eb);

        // if this was the last error, update the status and reevaluate the job
        if ($submission->submission_errors === null)
            $submission->status = Submission::STATUS_PROCESSING;

        // update the submission activity log
        $submission->addEvent(Auth::user()->id, "$bag changed to " . $request->input('curie') . " in " . $request->input('type'));
        
        $submission->save();

        $submission->job->reassess();

        return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => 'Submission Updated'],
                200);
                */
        //
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $job = $this->getEffectiveSubmitterQuery($request, 'jobs')
                        ->where('ident', $id)->first();

        if ($job === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        // Only allow deletion of draft jobs
        if ($job->status !== Job::STATUS_DRAFT) {
            return response()->json(['success' => 'false',
                'status_code' => 3009,
                'message' => 'Only draft jobs can be deleted'],
                200);
        }

        // Check if there's an active upload in progress
        $activeUpload = $job->documents()
            ->where('upload_state', Document::UPLOAD_STATE_UPLOADING)
            ->exists();

        if ($activeUpload) {
            return response()->json(['success' => 'false',
                'status_code' => 3010,
                'message' => 'Cannot delete job while submissions are being uploaded. Please wait for the upload to complete.'],
                200);
        }

        // Process submissions before deletion
        $submissions = $job->submissions()->get();

        // Collect SIDs of draft versions to restore is_most_recent on their originals
        $draftSids = [];

        foreach ($submissions as $submission) {
            // With versioning, draft versions are new records that should be deleted
            // The original published versions remain unchanged
            if ($submission->status === Submission::STATUS_DRAFT_REPUBLISH ||
                $submission->status === Submission::STATUS_DRAFT_UNPUBLISH ||
                $submission->status === Submission::STATUS_DRAFT_NEW) {

                // Track SIDs that need is_most_recent restored (only for republish/unpublish drafts)
                if ($submission->status !== Submission::STATUS_DRAFT_NEW) {
                    $draftSids[$submission->sid] = $submission->id;
                }

                // Delete draft version records
                $submission->forceDelete();

            }
        }

        // Restore is_most_recent=true on the previous versions
        foreach ($draftSids as $sid => $deletedId) {
            $previousVersion = Submission::where('sid', $sid)
                ->where('id', '!=', $deletedId)
                ->orderBy('version_number', 'desc')
                ->first();

            if ($previousVersion && !$previousVersion->is_most_recent) {
                $previousVersion->is_most_recent = true;
                $previousVersion->save();
            }
        }

        // Delete any documents associated with this job
        // Force delete to remove blob data from database
        $job->documents()->forceDelete();

        // Delete the job itself
        // Force delete draft jobs since they have no published data to preserve
        if ($job->status === Job::STATUS_DRAFT) {
            $job->forceDelete();
        } else {
            $job->delete();
        }

        return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => 'Job Removed'],
                200);
    }


    /**
     * @deprecated Use submit() endpoint instead
     * Stages job for publishing (legacy endpoint)
     */
    public function publish(Request $request, string $id)
    {
        // Redirect to the submit endpoint which uses the state machine
        return $this->submit($request, $id);
    }


    /**
     * @deprecated Use cancel functionality instead
     * Unstages job from publishing (legacy endpoint)
     */
    public function unpublish(Request $request, string $id)
    {
        $job = $this->getEffectiveSubmitterQuery($request, 'jobs')
                        ->with('submissions')
                        ->where('slug', $id)->first();

        if ($job === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        // Only submitted jobs can be unstaged
        if ($job->status !== Job::STATUS_SUBMITTED) {
            return response()->json(['success' => 'false',
                'status_code' => 3012,
                'message' => 'Only submitted jobs can be unstaged'],
                200);
        }

        try {
            // Cancel the job submission using state machine
            JobStateMachine::transition($job, Job::STATUS_DRAFT);

            // Transition all submissions back to draft states
            foreach ($job->submissions as $submission) {
                if (\App\Services\SubmissionStateMachine::isSubmittedState($submission->status)) {
                    $draftState = \App\Services\SubmissionStateMachine::getDraftStateFor($submission->status);
                    \App\Services\SubmissionStateMachine::transition($submission, $draftState);
                    $submission->save();
                }
            }

            $job->save();

            return response()->json(['success' => 'true',
                    'status_code' => 200,
                    'message' => 'Job UnStaged'],
                    200);

        } catch (\Exception $e) {
            return response()->json(['success' => 'false',
                    'status_code' => 3013,
                    'message' => $e->getMessage()],
                    200);
        }
    }


    /**
     * Submit the specified job (V2 State Machine).
     * Transitions job from draft to submitted and all submissions from draft_xxx to submitted_xxx.
     */
    public function submit(Request $request, string $id)
    {
        // Find the job by slug
        $job = Job::with('submissions')->where('slug', $id)->first();

        if ($job === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3001,
                    'message' => 'Job not found'],
                    200);

        // Check authorization: user must have access to this job's submitter
        $effectiveSubmitter = $this->getEffectiveSubmitter($request);
        if (!$effectiveSubmitter) {
            $effectiveSubmitter = Auth::user()?->submitter;
        }

        // Allow if user's effective submitter matches job's submitter, or if user is GenCC admin
        if ($job->submitter_id !== $effectiveSubmitter?->id && !Auth::user()->isGenccAdmin()) {
            return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);
        }

        try {
            // Use JobStateMachine to submit the job
            // This will validate state, transition job to 'submitted', and transition all submissions
            JobStateMachine::submit($job);
            $job->save();

            return response()->json(['success' => 'true',
                    'status_code' => 200,
                    'message' => 'Job Submitted'],
                    200);

        } catch (\Exception $e) {
            return response()->json(['success' => 'false',
                    'status_code' => 3011,
                    'message' => $e->getMessage()],
                    200);
        }
    }


    /**
     * Update the job name.
     */
    public function update_name(Request $request, string $id)
    {
        if (strpos($id, 'J-') === 0)
            $job = $this->getEffectiveSubmitterQuery($request, 'jobs')
                        ->where('slug', $id)->first();
        else
            $job = $this->getEffectiveSubmitterQuery($request, 'jobs')
                        ->where('ident', $id)->first();

        if ($job === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        // Allow favorites toggle on any job (modifies user preferences, not the job)
        // All other field updates are blocked on immutable jobs
        if ($request->input('type') !== 'favorites' && JobStateMachine::isImmutable($job)) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3020,
                'message' => 'This job is locked and cannot be edited in its current state'
            ], 200);
        }

        switch($request->input('type'))
        {
            case 'friendly':
                // make sure name does not already exist
                if ($this->getEffectiveSubmitterQuery($request, 'jobs')->where('friendly', $request->input('curie'))->exists())
                        return response()->json(['success' => 'false',
                            'status_code' => 3006,
                            'message' => 'Duplicate Name'],
                            200);

                // mark this job as eligible for publishing
                $job->update(['friendly' => $request->input('curie')]);
                break;
            case 'favorites':
                $preferences = Auth::user()->preferences;
                if (!isset($preferences->job_favorites))
                    $preferences->job_favorites = [];

                // Ensure job_favorites is always an array (not stdClass)
                if (is_object($preferences->job_favorites)) {
                    $preferences->job_favorites = (array) $preferences->job_favorites;
                }
                if (empty($preferences->job_favorites)) {
                    $preferences->job_favorites = [];
                }

                // Handle various falsy value representations (boolean false, string "false", empty string)
                $value = $request->input('value');
                $isFavorite = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                // If filter_var returns null (couldn't parse), treat empty/falsy as false
                if ($isFavorite === null) {
                    $isFavorite = !empty($value) && $value !== 'false' && $value !== '0';
                }

                if (!$isFavorite)
                {
                    if (($key = array_search($id, $preferences->job_favorites)) !== false)
                        unset($preferences->job_favorites[$key]);
                    // Re-index array after removal to prevent JSON object conversion
                    $preferences->job_favorites = array_values($preferences->job_favorites);
                }
                else
                {
                    // Only add if not already in favorites
                    if (!in_array($id, $preferences->job_favorites)) {
                        $preferences->job_favorites[] = $id;
                    }
                }
                Auth::user()->preferences = $preferences;
                Auth::user()->save();
                break;
        }

        return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => 'Job Updated'],
                200);
        
    }
}
