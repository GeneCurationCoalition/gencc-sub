<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Disease;
use App\Models\Inheritance;
use App\Models\Gene;
use App\Models\Classification;
use App\Models\Submission;
use App\Models\Submitter;
use App\Models\Alias;
use App\Models\Mechanism;

use Auth;
use Log;
use Carbon\Carbon;

use App\Models\Job;
use App\Models\Pubmed;
use App\Models\Action;

use App\Jobs\ProcessPubmed;

use App\Services\SubmissionStateMachine;
use App\Services\JobStateMachine;
use App\Services\SubmissionDuplicateDetection;

class SubmissionController extends Controller
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

        $jident = $request->input('job');

        if ($jident === null)
            return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        $job = Job::ident($jident)->first();
        if ($job === null)
            return response()->json(['success' => 'false',
                    'status_code' => 3003,
                    'message' => 'Unauthorized'],
                    200);

        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

        $submission = new Submission(
            [
                'user_id' => $user->id,
                'type' => Submission::TYPE_PORTAL_SUBMISSION,
                'sid' => null,
                'job_id' => $job->id,
                'gene_id' => null,
                'disease_id' => null,
                'original_disease_id' => null,
                'inheritance_id' => null,
                'classification_id' => null,
                'submitter_id' => $effectiveSubmitterId,
                'submission_date' => Carbon::now(),
                'submission_data' => [],
                'status' => Submission::STATUS_DRAFT_NEW,
                'submission_errors' => []
             ]
        );

        // build a skeleton submission data structure
        $submission->initialize_submission_data();

        // build the skeleton error bag
        $submission->initialize_submission_errors();

        $submission->save();

        // Job stays in draft status (new submissions don't change job status)

        return response()->json(['success' => 'true',
                'status_code' => 200,
                'sid' => $submission->ident,
                'message' => 'Submission Created'],
                200);

    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $disease = Disease::rosetta($id);

        if ($disease === null)
            return response()->json(['success' => 'false',
                'status_code' => 3001,
                'message' => 'Disease not found'],
                200);

        return $disease;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

        $submission = $this->getEffectiveSubmitterQuery($request, 'submissions')->where('sid', $id)->first();

        if ($submission === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);
        
        // Initialize warnings array for duplicate warnings
        $warnings = [];

        switch ($request->input('type'))
        {
            case 'inheritance':
                $inheritance = Inheritance::curie($request->input('curie'))->first();

                if ($inheritance === null)
                    return response()->json(['success' => 'false',
                        'status_code' => 3001,
                        'message' => 'Inheritance not found'],
                        200);

                // Check for duplicate gene-disease-MOI combination
                $duplicateCheck = SubmissionDuplicateDetection::checkForDuplicates(
                    $submission->submitter_id,
                    $submission->gene_id,
                    $submission->original_disease_id,
                    $inheritance->id,
                    $submission->id  // Exclude self from check
                );

                if ($duplicateCheck['has_blocking_duplicate']) {
                    $duplicate = $duplicateCheck['blocking_duplicates']->first();
                    return response()->json(['success' => 'false',
                        'status_code' => 3013,
                        'message' => SubmissionDuplicateDetection::formatBlockingErrorMessage($duplicate)],
                        200);
                }

                // update the submission inheritance
                $submission->inheritance_id = $inheritance->id;

                // Include warning if unpublished duplicate exists (not blocking)
                if ($duplicateCheck['has_unpublished_duplicate']) {
                    $warnings[] = [
                        'type' => 'unpublished_duplicate',
                        'sgc_ids' => $duplicateCheck['unpublished_duplicates']->pluck('sid')->toArray(),
                        'message' => SubmissionDuplicateDetection::formatUnpublishedWarningMessage($duplicateCheck['unpublished_duplicates'])
                    ];
                }

                $bags = ['moi_curie_id'];
                break;
            case 'classification':
                $classification = Classification::curie($request->input('curie'))->first();

                if ($classification === null)
                    return response()->json(['success' => 'false',
                        'status_code' => 3001,
                        'message' => 'Classification not found'],
                        200);

                // Prevent setting "Undefined" classification via API
                if ($classification->curie === 'GENCC:000000')
                    return response()->json(['success' => 'false',
                        'status_code' => 3002,
                        'message' => 'Undefined classification cannot be selected'],
                        200);

                // update the submission disease
                $submission->classification_id = $classification->id;
                $bags = ['classification_curie_id'];
                break;
            case 'disease':
                $uploadedCurie = $request->input('curie');

                // Find the original disease by exact CURIE (could be MONDO, OMIM, or Orphanet)
                $originalDisease = Disease::curie($uploadedCurie)->first();

                if ($originalDisease === null)
                    return response()->json(['success' => 'false',
                        'status_code' => 3001,
                        'message' => 'Disease not found'],
                        200);

                // Find the MONDO disease (normalized) using rosetta
                $mondoDisease = Disease::rosetta($uploadedCurie);

                if ($mondoDisease === null)
                    return response()->json(['success' => 'false',
                        'status_code' => 3001,
                        'message' => 'Disease not found - no MONDO mapping'],
                        200);

                // Check for duplicate gene-disease-MOI combination
                $duplicateCheck = SubmissionDuplicateDetection::checkForDuplicates(
                    $submission->submitter_id,
                    $submission->gene_id,
                    $originalDisease->id,  // Use the original disease ID for duplicate check
                    $submission->inheritance_id,
                    $submission->id  // Exclude self from check
                );

                if ($duplicateCheck['has_blocking_duplicate']) {
                    $duplicate = $duplicateCheck['blocking_duplicates']->first();
                    return response()->json(['success' => 'false',
                        'status_code' => 3013,
                        'message' => SubmissionDuplicateDetection::formatBlockingErrorMessage($duplicate)],
                        200);
                }

                // Update the submission with both diseases
                $submission->original_disease_id = $originalDisease->id;  // What was entered/uploaded
                $submission->disease_id = $mondoDisease->id;              // Normalized to MONDO

                // Update submission_data to preserve the original CURIE
                $submission_data = $submission->submission_data;
                $subdisease = $submission_data->disease;
                $subdisease->id = $uploadedCurie;
                $subdisease->name = $originalDisease->name;
                $submission_data->disease = $subdisease;
                $submission->submission_data = $submission_data;

                // Include warning if unpublished duplicate exists
                $warnings = [];
                if ($duplicateCheck['has_unpublished_duplicate']) {
                    $warnings[] = [
                        'type' => 'unpublished_duplicate',
                        'sgc_ids' => $duplicateCheck['unpublished_duplicates']->pluck('sid')->toArray(),
                        'message' => SubmissionDuplicateDetection::formatUnpublishedWarningMessage($duplicateCheck['unpublished_duplicates'])
                    ];
                }

                $bags = ['disease_curie_id'];
                break;
            case 'gene':
                // Prevent gene changes on republished submissions
                // Gene is immutable once a submission has been published (identified by publish_date or republish status)
                if ($submission->publish_date !== null ||
                    in_array($submission->status, [
                        Submission::STATUS_DRAFT_REPUBLISH,
                        Submission::STATUS_SUBMITTED_REPUBLISH
                    ])) {
                    return response()->json(['success' => 'false',
                        'status_code' => 3012,
                        'message' => 'Cannot change gene on a previously published submission. To submit a different gene-disease association, create a new submission instead.'],
                        200);
                }

                $gene = Gene::hgnc_id($request->input('curie'))->first();

                if ($gene === null)
                    return response()->json(['success' => 'false',
                        'status_code' => 3001,
                        'message' => 'Gene not found'],
                        200);

                // Check for duplicate gene-disease-MOI combination
                $duplicateCheck = SubmissionDuplicateDetection::checkForDuplicates(
                    $submission->submitter_id,
                    $gene->id,  // New gene ID
                    $submission->original_disease_id,
                    $submission->inheritance_id,
                    $submission->id  // Exclude self from check
                );

                if ($duplicateCheck['has_blocking_duplicate']) {
                    $duplicate = $duplicateCheck['blocking_duplicates']->first();
                    return response()->json(['success' => 'false',
                        'status_code' => 3013,
                        'message' => SubmissionDuplicateDetection::formatBlockingErrorMessage($duplicate)],
                        200);
                }

                // update the submission gene
                $submission->gene_id = $gene->id;

                // Include warning if unpublished duplicate exists
                $warnings = [];
                if ($duplicateCheck['has_unpublished_duplicate']) {
                    $warnings[] = [
                        'type' => 'unpublished_duplicate',
                        'sgc_ids' => $duplicateCheck['unpublished_duplicates']->pluck('sid')->toArray(),
                        'message' => SubmissionDuplicateDetection::formatUnpublishedWarningMessage($duplicateCheck['unpublished_duplicates'])
                    ];
                }

                $bags = ['gene_hgnc_id'];
                break;
            case 'mechanism_of_disease':
                $curie = $request->input('curie');
                $comment = $request->input('comment');

                $submission_data = $submission->submission_data;
                $mod = $submission_data->mechanism ?? null;
                if ($mod === null)
                    $mod = (object) [ 'id' => '', 'name' => '', 'comments' => ''];

                // If mechanism CURIE is provided and not empty, validate and update mechanism
                if (!empty($curie)) {
                    $mechanism = Mechanism::curie($curie)->first();
                    if ($mechanism === null)
                        return response()->json(['success' => 'false',
                            'status_code' => 3001,
                            'message' => 'Mechanism not found'],
                            200);

                    // Prevent setting "Undefined" mechanism via API
                    if ($mechanism->curie === 'GENCC:200000')
                        return response()->json(['success' => 'false',
                            'status_code' => 3002,
                            'message' => 'Undefined mechanism cannot be selected'],
                            200);

                    // Update mechanism fields
                    $submission->mechanism_id = $mechanism->id;
                    $mod->id = $curie;
                    $mod->name = $mechanism->name;
                }

                // Always update comment (can be updated independently of mechanism)
                $mod->comments = $comment ?? '';
                $submission_data->mechanism = $mod;
                $submission->submission_data = $submission_data;
                $bags = ['mech_of_disease'];
                break;
            case 'publishdate':
                $submission->publish_date = Carbon::parse($request->input('curie'));
                $bags = ['publish_date'];
                break;
            /*case 'reportdate':
                $submission->report_date = Carbon::parse($request->input('curie'));
                $bag = 'publish_date';
                break; */
            case 'notes':
                $submission_data = $submission->submission_data;
                // Initialize notes object if it doesn't exist
                if (!isset($submission_data->notes)) {
                    $submission_data->notes = (object) ['display' => '', 'private' => ''];
                }
                $notes = $submission_data->notes;
                $notes->display = $request->input('public') ?? '';
                $notes->private = $request->input('private') ?? '';
                $submission_data->notes = $notes;
                $submission->submission_data = $submission_data;
                $bags = ['notes'];
                break;
            case 'evidence':
                $submission_data = $submission->submission_data;
                $newevidence = [];
                $subevidence = [];
                if ($request->input('evidence') !== null)
                {
                    foreach ($request->input('evidence') as $pmid)
                    {
                        if (empty($pmid))
                            continue;

                        $pmid =  (stripos($pmid, "PMID:") === 0 ? substr($pmid, 5) : $pmid);

                        $pmid = trim($pmid);
            
                        if (!is_numeric($pmid))
                        {
                            // add to error bag
                            continue;
                        }
            
                        $newevidence[] = ['pmid' => $pmid];
                        $subevidence[] = $pmid;

                        // if pmid is not in pubmed table, add it.
                        $pmid = Pubmed::firstOrCreate(['pmid' => $pmid, 'uid' => $pmid],
                                                    ['status' => Pubmed::STATUS_INITIALIZING ]);


                    }
                }

                $submission->evidence = $subevidence;
                $submission_data->evidence = $newevidence;
                $submission->submission_data = $submission_data;

                // update the evidence pivot table entries
                $submission->pubmeds()->detach();
                foreach ($submission->evidence as $evidence)
                {
                    $pubmed = Pubmed::where('pmid', $evidence)->first();

                    if ($pubmed === null)
                        continue;

                    $submission->pubmeds()->attach($pubmed->id);
                }

                // Batch fetch PubMed data synchronously for immediate display
                $pmidsNeedingSummary = Pubmed::where('status', Pubmed::STATUS_INITIALIZING)
                    ->whereIn('pmid', $subevidence)
                    ->pluck('pmid')
                    ->toArray();

                if (!empty($pmidsNeedingSummary)) {
                    Pubmed::query_summary_batch($pmidsNeedingSummary);
                }

                $bags = ['invalid_pmid'];
                break;
            case 'report':
                $submission->report_url = $request->input('curie');
                $submission->report_date = Carbon::parse($request->input('date'));
                $submission_data = $submission->submission_data;
                $report = $submission_data->report;
                $report->ext_url = $request->input('curie');
                $report->display_date = $request->input('date');
                $submission_data->report = $report;
                $submission->submission_data = $submission_data;
                $bags = ['report_url' , 'report_date'];
                break;
            case 'criteria':
                $submission_data = $submission->submission_data;
                $criteria = $submission_data->criteria;
                $criteria->url = $request->input('url');
                $criteria->name = $request->input('name');
                $submission_data->criteria = $criteria;
                $submission->submission_data = $submission_data;
                if ($request->input('remember') == "true")
                {
                    $alias = new Alias([
                        'user_id' => Auth::user()->id,
                        'type' => Alias::TYPE_CRITERIA,
                        'subtype' => 0,
                        'submitter_id' => $effectiveSubmitterId,
                        'key' => $criteria->name,
                        'value' => $criteria->url,
                        'shared' => true,
                        'status' => 1
                    ]);

                    $alias->save();
                }
                $bags = ['criteria_url'];
                break;
            case 'primary_contributor':
                $submission_data = $submission->submission_data;
                $contributor = $submission_data->contributors;
                $contributor->primary = ['id' => $request->input('curie'), 'name' => $request->input('name')];
                $submission_data->contributors = $contributor;
                $submission->submission_data = $submission_data;
                $bags = ['invalid_contributor'];
                break;
            case 'version':
                $submission_data = $submission->submission_data;
                $version = $submission_data->version;
                $version->display = $request->input('curie');
                $version->internal = $request->input('private');
                $newreasons = [];
                foreach ($request->input('reasons') as $reason)
                    $newreasons[] = $reason;
                $version->reasons = $newreasons;
                $version->description = $request->input('description');
                $submission_data->version = $version;
                $submission->submission_data = $submission_data;
                $bags = ['invalid_version'];
                break;
            case 'favorites':
                $preferences = Auth::user()->preferences;
                if (!isset($preferences->sub_favorites))
                    $preferences->sub_favorites = [];

                // Ensure sub_favorites is always an array (not stdClass)
                if (is_object($preferences->sub_favorites)) {
                    $preferences->sub_favorites = (array) $preferences->sub_favorites;
                }
                if (empty($preferences->sub_favorites)) {
                    $preferences->sub_favorites = [];
                }

                if ($request->input('value') === "false"){
                    if (($key = array_search($submission->ident, $preferences->sub_favorites)) !== false)
                        unset($preferences->sub_favorites[$key]);
                }
                else
                {
                    $preferences->sub_favorites[] = $submission->ident;
                }
                Auth::user()->preferences = $preferences;
                Auth::user()->save();
                $bags = [];
                $bag = 'favorite';
                break;
            case 'friendly':
                // make sure name does not already exist
                // Check against the effective submitter's submissions
                if ($this->getEffectiveSubmitterQuery($request, 'submissions')->where('friendly', $request->input('curie'))->exists())
                        return response()->json(['success' => 'false',
                            'status_code' => 3006,
                            'message' => 'Duplicate Name'],
                            200);

                // mark this job as eligible for publishing
                $bags = [];
                $bag = 'friendly';
                $submission->friendly = $request->input('curie');
                break;
            case 'local_key':
                // make sure local_key does not already exist for this submitter
                $local_key = $request->input('local_key');
                Log::info("Checking local_key on " . $id . " local_key: " . $local_key);
                if (Auth::user()->submitter->submissions()->where('local_key', $local_key)->exists()) {
                    Log::info("Local key exists! " . $id . " local_key: " . $local_key);
                    return response()->json(['success' => 'false',
                        'status_code' => 3008,
                        'message' => 'Duplicate Local Key'],
                        200);
                }
                Log::info("Local key is unique! " . $id . " local_key: " . $local_key);

                // mark this job as eligible for publishing
                $bags = [];
                $bag = 'local_key';
                $submission->local_key = $request->input('local_key');
                break;
            case 'update_published':
                // LEGACY: Replaced by V2 state model - use draft_republish state instead
                return response()->json(['success' => 'false',
                        'status_code' => 3099,
                        'message' => 'Legacy update_published API removed. Use V2 state model with draft_republish.'],
                        200);
                break;
            case 'unpublish':
                // set some inficator to tell poster to unpublish
                Log::info("Unpublishing Submission " . $id);
                // Check if submission is published
                if ($submission->status !== Submission::STATUS_PUBLISHED)
                    return response()->json(['success' => 'false',
                            'status_code' => 3002,
                            'message' => 'Unauthorized'],
                            200);
                // create a new job
                $job = new Job(
                    ['user_id' => Auth::user()->id,
                     'submitter_id' => $effectiveSubmitterId,
                     'submission_date' => Carbon::now(),
                     'status' => Job::STATUS_DRAFT ]
                );
                $job->save();
                Log::info("Created Job for Unpublishing Submission " . $id);

                // create a command, but first null out any duplicates
                $checks = Action::status(Action::STATUS_PENDING)->where('submission_id', $submission->id)
                                                                ->where('type', Action::TYPE_UNPUBLISH)
                                                                ->where('submitter_id', $effectiveSubmitterId)
                                                                ->update(['status' => Action::STATUS_REMOVED]);

                $action = new Action([
                    'type' => Action::TYPE_UNPUBLISH,
                    'user_id' => Auth::user()->id,
                    'submitter_id'=> $effectiveSubmitterId,
                    'job_id' => $submission->job_id,
                    'submission_id' => $submission->id,
                    'local_key' => $submission->local_key,
                    'command' => ['action' => 'unpublish',
                                  'submitter_id'=> $effectiveSubmitterId,
                                  'submission_id' => $submission->sid,
                                  'local_key' => $submission->local_key,
                                  'timestamp' => Carbon::now()
                                ],
                    'status' => Action::STATUS_PENDING
                ]);
                $action->save();

                Log::info("Created Action Unpublishing Submission " . $id);
                // clone submission and attach to new job
                $submission->type = Submission::TYPE_PORTAL_SUBMISSION;
                $submission->job_id = $job->id;
                $submission->publish_date = null;
                // Use V2 status for workflow state - this is now draft_unpublish
                $submission->status = Submission::STATUS_DRAFT_UNPUBLISH;
                $submission->version = $submission->newversion();
                $submission->save();
                // update version
                return response()->json(['success' => 'true',
                                'status_code' => 200,
                                'message' => 'Submission Updated',
                                'ijob' => $job->slug],
                                200);
                break;
            case 'cancel_update':
                // LEGACY: Replaced by V2 state model - use SubmissionStateMachine::cancelDraft() instead
                return response()->json(['success' => 'false',
                                'status_code' => 3099,
                                'message' => 'Legacy cancel_update API removed. Use V2 state model with SubmissionStateMachine::cancelDraft().'],
                                200);
                break;

        }

        // update the submission errors
        $eb = $submission->submission_errors;
        if ($eb !== null) {
            foreach($bags as $bag)
                unset($eb->$bag);
            $submission->submission_errors = (empty((array) $eb) ? null : $eb);
        }

        // Note: Legacy status field no longer updated here.
        // Error state is now determined by submission_errors via has_errors accessor.

        // update the submission activity log
        $submission->addEvent(Auth::user()->id, $request->input('type') . " changed to " . $request->input('curie'));

        // update the workflow
        $submission_data = $submission->submission_data;
        $workflow = $submission_data->workflow;
        $workflow->last_update = Carbon::now();
        $submission_data->workflow = $workflow;
        $submission->submission_data = $submission_data;

        // track who last edited the submission
        $submission->last_edited_by = Auth::user()->id;

        $submission->save();

        // Build response with optional warnings
        $response = [
            'success' => 'true',
            'status_code' => 200,
            'message' => 'Submission Updated'
        ];

        if (!empty($warnings)) {
            $response['warnings'] = $warnings;
        }

        return response()->json($response, 200);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, string $id)
    {
        $submission = $this->getEffectiveSubmitterQuery($request, 'submissions')
                        ->where('sid', $id)->first();

        if ($submission === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        // Prevent deletion of published submissions or submissions that were previously published (have publish_date)
        if ($submission->status === Submission::STATUS_PUBLISHED ||
            $submission->publish_date !== null)
                return response()->json(['success' => 'false',
                    'status_code' => 3007,
                    'message' => 'Cannot delete published submissions or submissions being updated'],
                    200);

        $job = $submission->job;

        $submission->delete();

        return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => 'Submission Removed'],
                200);
    }


    /**
     * Cancel a submission (V2 state transitions)
     * Transitions draft_republish back to published/unpublished or draft_unpublish back to published
     */
    public function cancel(Request $request, string $id)
    {
        $submission = $this->getEffectiveSubmitterQuery($request, 'submissions')
                        ->where('sid', $id)->first();

        if ($submission === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        try {
            // Use state machine to cancel
            SubmissionStateMachine::cancel($submission);
            $submission->save();

            // Reload to get job relationship
            $submission->load('job');

            return response()->json(['success' => 'true',
                    'status_code' => 200,
                    'message' => 'Submission Cancelled',
                    'status' => $submission->status,
                    'job_ident' => $submission->job->ident ?? null],
                    200);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false',
                    'status_code' => 3008,
                    'message' => 'Cannot cancel: ' . $e->getMessage()],
                    200);
        }
    }


    /**
     * Initiate republish workflow for a published submission
     * Transitions published → draft_republish and moves to draft job
     */
    public function republish(Request $request, string $id)
    {
        $submission = $this->getEffectiveSubmitterQuery($request, 'submissions')
                        ->where('sid', $id)->first();

        if ($submission === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        try {
            $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

            // Check for submitted jobs - cannot create/use draft job if submitted job exists
            $hasSubmittedJob = Job::where('submitter_id', $effectiveSubmitterId)
                ->where('status', Job::STATUS_SUBMITTED)
                ->exists();

            if ($hasSubmittedJob) {
                return response()->json(['success' => 'false',
                    'status_code' => 3011,
                    'message' => 'Cannot republish submission: A submitted job is currently being processed. Please wait until processing is complete before creating new draft submissions.'],
                    200);
            }

            // Find or create a draft job for this submitter
            // Look for the most recent draft job
            $job = Job::where('submitter_id', $effectiveSubmitterId)
                ->where('status', Job::STATUS_DRAFT)
                ->orderBy('id', 'desc')
                ->first();

            if ($job === null) {
                // Create a new draft job
                $job = new Job([
                    'user_id' => Auth::user()->id,
                    'submitter_id' => $effectiveSubmitterId,
                    'submission_date' => Carbon::now(),
                    'status' => Job::STATUS_DRAFT
                ]);
                $job->save();
                Log::info("Created new draft job {$job->slug} for republishing submission {$id}");
            } else {
                Log::info("Using existing draft job {$job->slug} for republishing submission {$id}");
            }

            // Use state machine to transition to draft_republish
            // This will automatically store origin_job_id and origin_state
            SubmissionStateMachine::transition(
                $submission,
                Submission::STATUS_DRAFT_REPUBLISH,
                $submission->status  // Store origin state
            );

            // Move submission to the draft job
            $submission->job_id = $job->id;
            $submission->save();

            return response()->json(['success' => 'true',
                    'status_code' => 200,
                    'message' => 'Submission moved to draft for republishing',
                    'status' => $submission->status,
                    'job' => $job->slug,
                    'submission_ident' => $submission->ident],
                    200);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false',
                    'status_code' => 3009,
                    'message' => 'Cannot republish: ' . $e->getMessage()],
                    200);
        }
    }


    /**
     * Initiate unpublish workflow for a published submission
     * Transitions published → draft_unpublish and moves to draft job
     */
    public function unpublish(Request $request, string $id)
    {
        $submission = $this->getEffectiveSubmitterQuery($request, 'submissions')
                        ->where('sid', $id)->first();

        if ($submission === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        try {
            $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

            // Check for submitted jobs - cannot create/use draft job if submitted job exists
            $hasSubmittedJob = Job::where('submitter_id', $effectiveSubmitterId)
                ->where('status', Job::STATUS_SUBMITTED)
                ->exists();

            if ($hasSubmittedJob) {
                return response()->json(['success' => 'false',
                    'status_code' => 3012,
                    'message' => 'Cannot unpublish submission: A submitted job is currently being processed. Please wait until processing is complete before creating new draft submissions.'],
                    200);
            }

            // Find or create a draft job for this submitter
            // Look for the most recent draft job
            $job = Job::where('submitter_id', $effectiveSubmitterId)
                ->where('status', Job::STATUS_DRAFT)
                ->orderBy('id', 'desc')
                ->first();

            if ($job === null) {
                // Create a new draft job
                $job = new Job([
                    'user_id' => Auth::user()->id,
                    'submitter_id' => $effectiveSubmitterId,
                    'submission_date' => Carbon::now(),
                    'status' => Job::STATUS_DRAFT
                ]);
                $job->save();
                Log::info("Created new draft job {$job->slug} for unpublishing submission {$id}");
            } else {
                Log::info("Using existing draft job {$job->slug} for unpublishing submission {$id}");
            }

            // Use state machine to transition to draft_unpublish
            // This will automatically store origin_job_id and origin_state
            SubmissionStateMachine::transition(
                $submission,
                Submission::STATUS_DRAFT_UNPUBLISH,
                $submission->status  // Store origin state
            );

            // Move submission to the draft job
            $submission->job_id = $job->id;
            $submission->save();

            return response()->json(['success' => 'true',
                    'status_code' => 200,
                    'message' => 'Submission moved to draft for unpublishing',
                    'status' => $submission->status,
                    'job' => $job->slug],
                    200);
        } catch (\Exception $e) {
            return response()->json(['success' => 'false',
                    'status_code' => 3010,
                    'message' => 'Cannot unpublish: ' . $e->getMessage()],
                    200);
        }
    }

    /**
     * Handle bulk actions on multiple submissions
     */
    public function bulkAction(Request $request)
    {
        $user = Auth::user();

        if ($user === null) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3001,
                'message' => 'Unauthorized'
            ], 200);
        }

        $action = $request->input('action');
        $sids = $request->input('sids');

        if (empty($action) || empty($sids) || !is_array($sids)) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3002,
                'message' => 'Invalid request: action and sids array required'
            ], 200);
        }

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($sids as $sid) {
            try {
                // Use getEffectiveSubmitterQuery to automatically scope to submissions
                // the user has access to (same pattern as republish/unpublish endpoints)
                $submission = $this->getEffectiveSubmitterQuery($request, 'submissions')
                    ->where('sid', $sid)
                    ->first();

                if ($submission === null) {
                    $errors[] = "Submission {$sid} not found or unauthorized";
                    $errorCount++;
                    continue;
                }

                // Execute the appropriate action
                switch ($action) {
                    case 'republish':
                        $this->executeSingleRepublish($submission, $user, $request);
                        $successCount++;
                        break;

                    case 'unpublish':
                        $this->executeSingleUnpublish($submission, $user, $request);
                        $successCount++;
                        break;

                    case 'cancel':
                    case 'restore':
                        $this->executeSingleRestore($submission);
                        $successCount++;
                        break;

                    case 'delete':
                        $submission->delete();
                        $successCount++;
                        break;

                    default:
                        $errors[] = "Unknown action: {$action}";
                        $errorCount++;
                }
            } catch (\Exception $e) {
                $errors[] = "Error processing {$sid}: " . $e->getMessage();
                $errorCount++;
                Log::error("Bulk action error for {$sid}: " . $e->getMessage());
            }
        }

        $message = "Bulk {$action} completed: {$successCount} succeeded";
        if ($errorCount > 0) {
            $message .= ", {$errorCount} failed";
        }

        return response()->json([
            'success' => $errorCount === 0 ? 'true' : 'partial',
            'status_code' => 200,
            'message' => $message,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errors
        ], 200);
    }

    /**
     * Execute republish for a single submission (used by bulk action)
     */
    private function executeSingleRepublish($submission, $user, $request)
    {
        Log::info("executeSingleRepublish called for {$submission->sid}, current status: {$submission->status}");

        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

        // Check for submitted jobs - cannot create/use draft job if submitted job exists
        $hasSubmittedJob = Job::where('submitter_id', $effectiveSubmitterId)
            ->where('status', Job::STATUS_SUBMITTED)
            ->exists();

        if ($hasSubmittedJob) {
            throw new \Exception('Cannot republish submission: A submitted job is currently being processed');
        }

        // Find or create draft job for this submitter
        $job = Job::where('submitter_id', $effectiveSubmitterId)
            ->where('status', Job::STATUS_DRAFT)
            ->first();

        if ($job === null) {
            $job = new Job([
                'user_id' => $user->id,
                'submitter_id' => $effectiveSubmitterId,
                'submission_date' => Carbon::now(),
                'status' => Job::STATUS_DRAFT
            ]);
            $job->save();
            Log::info("Created new draft job {$job->slug} for republishing submission {$submission->sid}");
        } else {
            Log::info("Using existing draft job {$job->slug} for republishing submission {$submission->sid}");
        }

        // Use state machine to transition to draft_republish
        Log::info("About to transition {$submission->sid} to draft_republish");
        $submission = SubmissionStateMachine::transition(
            $submission,
            Submission::STATUS_DRAFT_REPUBLISH
        );
        Log::info("After transition, {$submission->sid} status is: {$submission->status}");

        // Move submission to the draft job
        $submission->job_id = $job->id;
        $submission->save();
        Log::info("Saved {$submission->sid} with job_id: {$submission->job_id}, status: {$submission->status}");
    }

    /**
     * Execute unpublish for a single submission (used by bulk action)
     */
    private function executeSingleUnpublish($submission, $user, $request)
    {
        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

        // Check for submitted jobs - cannot create/use draft job if submitted job exists
        $hasSubmittedJob = Job::where('submitter_id', $effectiveSubmitterId)
            ->where('status', Job::STATUS_SUBMITTED)
            ->exists();

        if ($hasSubmittedJob) {
            throw new \Exception('Cannot unpublish submission: A submitted job is currently being processed');
        }

        // Find or create draft job for this submitter
        $job = Job::where('submitter_id', $effectiveSubmitterId)
            ->where('status', Job::STATUS_DRAFT)
            ->first();

        if ($job === null) {
            $job = new Job([
                'user_id' => $user->id,
                'submitter_id' => $effectiveSubmitterId,
                'submission_date' => Carbon::now(),
                'status' => Job::STATUS_DRAFT
            ]);
            $job->save();
        }

        // Use state machine to transition to draft_unpublish
        $submission = SubmissionStateMachine::transition(
            $submission,
            Submission::STATUS_DRAFT_UNPUBLISH,
            $submission->status  // Store origin state
        );

        // Move submission to the draft job
        $submission->job_id = $job->id;
        $submission->save();
    }

    /**
     * Execute restore for a single submission (used by bulk action)
     */
    private function executeSingleRestore($submission)
    {
        // Use state machine cancel method to restore submission to previous state
        SubmissionStateMachine::cancel($submission);
        $submission->save();
    }
}
