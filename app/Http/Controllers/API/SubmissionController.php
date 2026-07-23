<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
use App\Exports\SubmissionsTemplateExport;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

        $effectiveSubmitter = $this->getEffectiveSubmitter($request);

        try {
            $submission = DB::transaction(function () use ($job, $user, $effectiveSubmitter) {
                // Serialize creation with job submission and recheck editability while locked.
                $job = Job::query()
                    ->whereKey($job->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($job->status !== Job::STATUS_DRAFT) {
                    throw new \RuntimeException('Submissions can only be added to draft jobs');
                }

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
                        'submitter_id' => $effectiveSubmitter?->id,
                        // created_at is auto-set by Laravel
                        'submission_data' => [],
                        'status' => Submission::STATUS_DRAFT_NEW,
                        'submission_errors' => [],
                     ]
                );

                // build a skeleton submission data structure
                $submission->initialize_submission_data();

                // build the skeleton error bag
                $submission->initialize_submission_errors();

                $submission->save();

                // SID generation happens on the first save. Mirror it and the effective submitter pair.
                $submission->syncSubmittedMetadata($effectiveSubmitter?->curie, $effectiveSubmitter?->name);
                $submission->save();

                return $submission;
            });
        } catch (\RuntimeException $e) {
            return response()->json(['success' => 'false',
                    'status_code' => 3011,
                    'message' => $e->getMessage()],
                    200);
        }

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

        // Try to find by ident first (for version-specific operations like favorites)
        // Fall back to sid for backwards compatibility
        $submission = $this->getEffectiveSubmitterQuery($request, 'submissions')->where('ident', $id)->first();
        if ($submission === null) {
            $submission = $this->getEffectiveSubmitterQuery($request, 'submissions')->where('sid', $id)->first();
        }

        if ($submission === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Unauthorized'],
                    200);

        // Allow favorites toggle on any submission (modifies user preferences, not the submission)
        // All other field updates are blocked on immutable submissions
        $type = $request->input('type');
        if ($type !== 'favorites' && SubmissionStateMachine::isImmutable($submission)) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3020,
                'message' => 'This submission is locked and cannot be edited in its current state'
            ], 200);
        }

        // Initialize warnings array for duplicate warnings
        $warnings = [];

        switch ($type)
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
                $submission->recordSubmittedRelationship('moi', $inheritance->curie, $inheritance->name);

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
                $submission->recordSubmittedRelationship('classification', $classification->curie, $classification->name);
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

                $submission->recordSubmittedRelationship('disease', $uploadedCurie, $originalDisease->name);

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
                $submission->recordSubmittedRelationship('gene', $gene->hgnc_id, $gene->symbol);

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

                // Store normalized PMID data
                $allPmids = implode(',', $pmids);
                $normResult = \App\Services\PmidNormalizer::normalize($allPmids);
                $submission->normalized_pmids = !empty($normResult['pmids']) ? implode(',', $normResult['pmids']) : null;
                $submission->pmid_issues = !empty($normResult['issues']) ? $normResult['issues'] : null;

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

                // Handle various representations of true/false from JavaScript
                // Boolean false can be sent as: false, "false", "0", "", 0
                $value = $request->input('value');
                $isFalse = $value === false || $value === "false" || $value === "0" || $value === "" || $value === 0 || $value === null;

                if ($isFalse) {
                    // Remove from favorites
                    if (($key = array_search($submission->ident, $preferences->sub_favorites)) !== false) {
                        unset($preferences->sub_favorites[$key]);
                        // Re-index array after removal
                        $preferences->sub_favorites = array_values($preferences->sub_favorites);
                    }
                } else {
                    // Add to favorites (avoid duplicates)
                    if (!in_array($submission->ident, $preferences->sub_favorites)) {
                        $preferences->sub_favorites[] = $submission->ident;
                    }
                }
                Auth::user()->preferences = $preferences;
                Auth::user()->save();
                // Return early - favorites only modifies user preferences, not the submission
                return response()->json([
                    'success' => 'true',
                    'status_code' => 200,
                    'message' => 'Favorite status updated'
                ], 200);
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
                $submission->syncSubmittedMetadata($submission->submitter?->curie, $submission->submitter?->name);
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
                $submission->syncSubmittedMetadata($submission->submitter?->curie, $submission->submitter?->name);
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
                     // created_at is auto-set by Laravel
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

        // Use state machine to check if deletion is allowed
        // Only new (v1) submissions in draft state can be deleted
        if (!SubmissionStateMachine::canDelete($submission->status)) {
            return response()->json(['success' => 'false',
                    'status_code' => 3007,
                    'message' => 'Cannot delete submissions in this state. Only new submissions can be deleted.'],
                    200);
        }

        // Additional check: can only delete if job is in draft state
        if ($submission->job && $submission->job->status !== Job::STATUS_DRAFT) {
            return response()->json(['success' => 'false',
                    'status_code' => 3008,
                    'message' => 'Cannot delete submissions in a submitted job. Cancel the job submission first.'],
                    200);
        }

        $jobIdent = $submission->job?->ident;

        $submission->delete();

        return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => 'Submission Removed',
                'job_ident' => $jobIdent],
                200);
    }


    /**
     * Cancel a submission (V2 state transitions)
     *
     * NEW VERSIONING APPROACH:
     * For draft_republish: Simply DELETE the draft version record.
     *                      The original published version remains unchanged.
     * For draft_unpublish: Transition back to published (no deletion needed,
     *                      unpublish doesn't create a new version).
     */
    public function cancel(Request $request, string $id)
    {
        // For versioned submissions, we need to find the draft version
        // The $id is the SID which may have multiple versions
        $submission = $this->getEffectiveSubmitterQuery($request, 'submissions')
                        ->where('sid', $id)
                        ->whereIn('status', [
                            Submission::STATUS_DRAFT_REPUBLISH,
                            Submission::STATUS_DRAFT_UNPUBLISH
                        ])
                        ->first();

        if ($submission === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'No draft submission found to cancel'],
                    200);

        try {
            $status = $submission->status;
            $versionNumber = $submission->version_number ?? 1;
            $sid = $submission->sid;

            // NEW VERSIONING: Both draft_republish and draft_unpublish create new version records
            // Simply delete the draft version - the original published version remains unchanged
            $actionType = $status === Submission::STATUS_DRAFT_REPUBLISH ? 'republish' : 'unpublish';
            Log::info("Cancelling draft_{$actionType}: Deleting version {$versionNumber} of {$id}");

            // Get the job before deleting
            $jobIdent = $submission->job->ident ?? null;

            // Delete the draft version (soft delete)
            $submission->delete();

            // Restore is_most_recent=true on the previous version (the most recent remaining version)
            $previousVersion = Submission::where('sid', $sid)
                ->where('id', '!=', $submission->id)
                ->orderBy('version_number', 'desc')
                ->first();

            if ($previousVersion && !$previousVersion->is_most_recent) {
                $previousVersion->is_most_recent = true;
                $previousVersion->save();
                Log::info("Restored is_most_recent=true on previous version {$previousVersion->version_number} of {$sid}");
            }

            return response()->json(['success' => 'true',
                    'status_code' => 200,
                    'message' => "Draft {$actionType} (version {$versionNumber}) deleted",
                    'status' => 'deleted',
                    'job_ident' => $jobIdent],
                    200);

        } catch (\Exception $e) {
            Log::error("Cancel failed for {$id}: " . $e->getMessage());
            return response()->json(['success' => 'false',
                    'status_code' => 3008,
                    'message' => 'Cannot cancel: ' . $e->getMessage()],
                    200);
        }
    }


    /**
     * Initiate republish workflow for a published submission
     *
     * NEW VERSIONING APPROACH:
     * Instead of transitioning the existing submission, we now:
     * 1. Keep the original published submission unchanged
     * 2. Create a NEW submission record with version_number + 1
     * 3. The new record has status = draft_republish
     *
     * This preserves the complete version history.
     */
    public function republish(Request $request, string $id)
    {
        // Find the current LIVE submission by SID (published OR unpublished)
        // Must be is_live=true (the current released state for this SGC ID)
        // Note: Both published and unpublished can be is_live=true because:
        // - Published with is_live=true = visible in gencc-search
        // - Unpublished with is_live=true = hidden in gencc-search (but that's the current state)
        $originalSubmission = $this->getEffectiveSubmitterQuery($request, 'submissions')
                        ->where('sid', $id)
                        ->whereIn('status', [Submission::STATUS_PUBLISHED, Submission::STATUS_UNPUBLISHED])
                        ->where('is_live', true)
                        ->first();

        if ($originalSubmission === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Submission not found or not in a republishable state (must be the current live version)'],
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

            // Check if there's already a draft version for this SID
            $existingDraft = $this->getEffectiveSubmitterQuery($request, 'submissions')
                ->where('sid', $id)
                ->whereIn('status', [Submission::STATUS_DRAFT_REPUBLISH, Submission::STATUS_SUBMITTED_REPUBLISH])
                ->first();

            if ($existingDraft) {
                return response()->json(['success' => 'false',
                    'status_code' => 3012,
                    'message' => 'A draft version already exists for this submission'],
                    200);
            }

            // Find or create a draft job for this submitter
            $job = Job::where('submitter_id', $effectiveSubmitterId)
                ->where('status', Job::STATUS_DRAFT)
                ->orderBy('id', 'desc')
                ->first();

            if ($job === null) {
                // Create a new draft job
                $job = new Job([
                    'user_id' => Auth::user()->id,
                    'submitter_id' => $effectiveSubmitterId,
                    // created_at is auto-set by Laravel
                    'status' => Job::STATUS_DRAFT
                ]);
                $job->save();
                Log::info("Created new draft job {$job->slug} for republishing submission {$id}");
            } else {
                Log::info("Using existing draft job {$job->slug} for republishing submission {$id}");
            }

            // Calculate the next version number
            $maxVersion = Submission::where('sid', $id)->max('version_number') ?? 1;
            $newVersionNumber = $maxVersion + 1;

            // Create a NEW submission record as a copy of the original
            // This preserves the original submission unchanged
            // Exclude 'ident' from replication - it must be unique and will be auto-generated
            $newSubmission = $originalSubmission->replicate(['ident']);
            $newSubmission->ident = \Illuminate\Support\Str::uuid()->toString(); // Generate new unique ident
            $newSubmission->version_number = $newVersionNumber;
            $newSubmission->status = Submission::STATUS_DRAFT_REPUBLISH;
            $newSubmission->job_id = $job->id;
            $newSubmission->released_at = null; // New version not yet released

            // Clear "description of change" field - user should enter a new one for this version
            $submissionData = Submission::normalizeJsonField($newSubmission->submission_data);
            if (isset($submissionData['version'])) {
                $submissionData['version']['description'] = '';
                $newSubmission->submission_data = $submissionData;
            }

            $newSubmission->save();

            // Mark the original submission as not most recent (new draft is now the most recent version)
            $originalSubmission->is_most_recent = false;
            $originalSubmission->save();

            // Copy pubmed associations
            $pubmedIds = $originalSubmission->pubmeds()->pluck('pubmeds.id')->toArray();
            $newSubmission->pubmeds()->sync($pubmedIds);

            Log::info("Created new version {$newVersionNumber} of submission {$id} (new id: {$newSubmission->id}), marked original as not most recent");

            return response()->json(['success' => 'true',
                    'status_code' => 200,
                    'message' => "Created new version {$newVersionNumber} for republishing",
                    'status' => $newSubmission->status,
                    'version_number' => $newVersionNumber,
                    'job' => $job->slug,
                    'submission_ident' => $newSubmission->ident],
                    200);
        } catch (\Exception $e) {
            Log::error("Republish failed for {$id}: " . $e->getMessage());
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
        // Find the current LIVE published submission by SID
        // Must be is_live=true (the currently publicly accessible version)
        // Only live published submissions can be unpublished
        $originalSubmission = $this->getEffectiveSubmitterQuery($request, 'submissions')
                        ->where('sid', $id)
                        ->where('status', Submission::STATUS_PUBLISHED)
                        ->where('is_live', true)
                        ->first();

        if ($originalSubmission === null)
                return response()->json(['success' => 'false',
                    'status_code' => 3002,
                    'message' => 'Submission not found or not in unpublishable state (must be the current live version)'],
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
                    'message' => 'Cannot unpublish submission: A submitted job is currently being processed. Please wait until processing is complete before creating new draft submissions.'],
                    200);
            }

            // Check if there's already a draft version for this SID (unpublish or republish)
            $existingDraft = $this->getEffectiveSubmitterQuery($request, 'submissions')
                ->where('sid', $id)
                ->whereIn('status', [
                    Submission::STATUS_DRAFT_UNPUBLISH,
                    Submission::STATUS_SUBMITTED_UNPUBLISH,
                    Submission::STATUS_DRAFT_REPUBLISH,
                    Submission::STATUS_SUBMITTED_REPUBLISH
                ])
                ->first();

            if ($existingDraft) {
                return response()->json(['success' => 'false',
                    'status_code' => 3012,
                    'message' => 'A draft version already exists for this submission'],
                    200);
            }

            // Find or create a draft job for this submitter
            $job = Job::where('submitter_id', $effectiveSubmitterId)
                ->where('status', Job::STATUS_DRAFT)
                ->orderBy('id', 'desc')
                ->first();

            if ($job === null) {
                // Create a new draft job
                $job = new Job([
                    'user_id' => Auth::user()->id,
                    'submitter_id' => $effectiveSubmitterId,
                    // created_at is auto-set by Laravel
                    'status' => Job::STATUS_DRAFT
                ]);
                $job->save();
                Log::info("Created new draft job {$job->slug} for unpublishing submission {$id}");
            } else {
                Log::info("Using existing draft job {$job->slug} for unpublishing submission {$id}");
            }

            // Calculate the next version number
            $maxVersion = Submission::where('sid', $id)->max('version_number') ?? 1;
            $newVersionNumber = $maxVersion + 1;

            // Create a NEW submission record as a copy of the original
            // This preserves the original submission unchanged
            $newSubmission = $originalSubmission->replicate(['ident']);
            $newSubmission->ident = \Illuminate\Support\Str::uuid()->toString();
            $newSubmission->version_number = $newVersionNumber;
            $newSubmission->status = Submission::STATUS_DRAFT_UNPUBLISH;
            $newSubmission->job_id = $job->id;
            $newSubmission->released_at = null;

            // Clear "description of change" field - user should enter a new one for this version
            $submissionData = Submission::normalizeJsonField($newSubmission->submission_data);
            if (isset($submissionData['version'])) {
                $submissionData['version']['description'] = '';
                $newSubmission->submission_data = $submissionData;
            }

            $newSubmission->save();

            // Mark the original submission as not most recent (historical)
            $originalSubmission->is_most_recent = false;
            $originalSubmission->save();

            // Copy pubmed associations
            $pubmedIds = $originalSubmission->pubmeds()->pluck('pubmeds.id')->toArray();
            $newSubmission->pubmeds()->sync($pubmedIds);

            Log::info("Created new version {$newVersionNumber} of submission {$id} for unpublishing (new id: {$newSubmission->id}), marked original as not most recent");

            return response()->json(['success' => 'true',
                    'status_code' => 200,
                    'message' => "Created new version {$newVersionNumber} for unpublishing",
                    'status' => $newSubmission->status,
                    'version_number' => $newVersionNumber,
                    'job' => $job->slug,
                    'submission_ident' => $newSubmission->ident],
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
        $idents = $request->input('idents');

        // Support both sids and idents - idents are used for delete to target specific versions
        $useIdents = !empty($idents) && is_array($idents);
        $ids = $useIdents ? $idents : $sids;

        if (empty($action) || empty($ids) || !is_array($ids)) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3002,
                'message' => 'Invalid request: action and sids/idents array required'
            ], 200);
        }

        // Extend execution time for large bulk operations
        $count = count($ids);
        if ($count > 100) {
            set_time_limit(max(300, $count)); // At least 1 second per item, minimum 5 minutes
        }

        // For delete action, use optimized bulk delete
        if ($action === 'delete') {
            return $this->executeBulkDelete($request, $ids, $useIdents);
        }

        $successCount = 0;
        $errorCount = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                // Use getEffectiveSubmitterQuery to automatically scope to submissions
                // the user has access to (same pattern as republish/unpublish endpoints)
                // When using idents, query by ident to find the specific version
                // When using sids, query by sid (for republish/unpublish operations)
                $query = $this->getEffectiveSubmitterQuery($request, 'submissions');
                if ($useIdents) {
                    $query->where('ident', $id);
                } else {
                    $query->where('sid', $id);
                }
                $submission = $query->first();

                if ($submission === null) {
                    $errors[] = "Submission {$id} not found or unauthorized";
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
                        // With versioning, cancel simply deletes the draft version
                        // The original published version remains unchanged
                        if (in_array($submission->status, [
                            Submission::STATUS_DRAFT_REPUBLISH,
                            Submission::STATUS_DRAFT_UNPUBLISH
                        ])) {
                            $submissionSid = $submission->sid;
                            $submissionId = $submission->id;
                            $submission->delete();

                            // Restore is_most_recent=true on the previous version
                            $previousVersion = Submission::where('sid', $submissionSid)
                                ->where('id', '!=', $submissionId)
                                ->orderBy('version_number', 'desc')
                                ->first();

                            if ($previousVersion && !$previousVersion->is_most_recent) {
                                $previousVersion->is_most_recent = true;
                                $previousVersion->save();
                            }

                            $successCount++;
                        } else {
                            $errors[] = "Cannot cancel {$id}: not in a draft republish/unpublish state";
                            $errorCount++;
                        }
                        break;

                    default:
                        $errors[] = "Unknown action: {$action}";
                        $errorCount++;
                }
            } catch (\Exception $e) {
                $errors[] = "Error processing {$id}: " . $e->getMessage();
                $errorCount++;
                Log::error("Bulk action error for {$id}: " . $e->getMessage());
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
     * Optimized bulk delete - deletes all matching submissions in a single query
     */
    private function executeBulkDelete(Request $request, array $ids, bool $useIdents)
    {
        // Get all submissions that can be deleted
        $query = $this->getEffectiveSubmitterQuery($request, 'submissions');
        if ($useIdents) {
            $query->whereIn('ident', $ids);
        } else {
            $query->whereIn('sid', $ids);
        }

        // Only allow deleting draft submissions (not published)
        $draftStatuses = [
            Submission::STATUS_DRAFT_NEW,
            Submission::STATUS_DRAFT_REPUBLISH,
            Submission::STATUS_DRAFT_UNPUBLISH
        ];

        $submissions = $query->whereIn('status', $draftStatuses)->get();
        $foundIds = $useIdents
            ? $submissions->pluck('ident')->toArray()
            : $submissions->pluck('sid')->toArray();
        $notFoundIds = array_diff($ids, $foundIds);

        $successCount = $submissions->count();
        if ($successCount > 0) {
            // For draft_republish and draft_unpublish submissions, we need to restore
            // is_most_recent=true on the previous version before deleting
            $republishUnpublishSids = $submissions
                ->whereIn('status', [
                    Submission::STATUS_DRAFT_REPUBLISH,
                    Submission::STATUS_DRAFT_UNPUBLISH
                ])
                ->pluck('sid')
                ->unique()
                ->toArray();

            // Get the IDs of submissions being deleted (to exclude from the restore query)
            $submissionIds = $submissions->pluck('id')->toArray();

            // Bulk soft delete first
            Submission::whereIn('id', $submissionIds)->delete();

            // Now restore is_most_recent on the previous versions
            // Find the most recent non-deleted version for each SID and mark it as most recent
            if (!empty($republishUnpublishSids)) {
                foreach ($republishUnpublishSids as $sid) {
                    $previousVersion = Submission::where('sid', $sid)
                        ->orderBy('version_number', 'desc')
                        ->first();

                    if ($previousVersion && !$previousVersion->is_most_recent) {
                        $previousVersion->is_most_recent = true;
                        $previousVersion->save();
                    }
                }
            }
        }

        $errorCount = count($notFoundIds);
        $message = "Bulk delete completed: {$successCount} deleted";
        if ($errorCount > 0) {
            $message .= ", {$errorCount} not found or not deletable";
        }

        return response()->json([
            'success' => $errorCount === 0 ? 'true' : 'partial',
            'status_code' => 200,
            'message' => $message,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'errors' => $errorCount > 0 ? ["Some submissions not found or not in draft status"] : []
        ], 200);
    }

    /**
     * Handle bulk favorites operation (separate from bulkAction due to different pattern)
     * Updates favorites for multiple submissions in a single request
     */
    public function bulkFavorites(Request $request)
    {
        $user = Auth::user();

        if ($user === null) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3001,
                'message' => 'Unauthorized'
            ], 200);
        }

        $action = $request->input('action'); // 'favorite' or 'unfavorite'
        $idents = $request->input('idents');

        if (empty($action) || !in_array($action, ['favorite', 'unfavorite'])) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3002,
                'message' => 'Invalid request: action must be "favorite" or "unfavorite"'
            ], 200);
        }

        if (empty($idents) || !is_array($idents)) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3002,
                'message' => 'Invalid request: idents array required'
            ], 200);
        }

        // Get all submissions by idents that user has access to
        $submissions = $this->getEffectiveSubmitterQuery($request, 'submissions')
            ->whereIn('ident', $idents)
            ->get();

        $foundIdents = $submissions->pluck('ident')->toArray();
        $notFoundIdents = array_diff($idents, $foundIdents);

        // Update user preferences
        $preferences = $user->preferences;
        if (!isset($preferences->sub_favorites)) {
            $preferences->sub_favorites = [];
        }

        // Ensure sub_favorites is always an array (not stdClass)
        if (is_object($preferences->sub_favorites)) {
            $preferences->sub_favorites = (array) $preferences->sub_favorites;
        }
        if (empty($preferences->sub_favorites)) {
            $preferences->sub_favorites = [];
        }

        $successCount = 0;

        if ($action === 'favorite') {
            // Add all found idents to favorites (avoiding duplicates)
            foreach ($foundIdents as $ident) {
                if (!in_array($ident, $preferences->sub_favorites)) {
                    $preferences->sub_favorites[] = $ident;
                    $successCount++;
                } else {
                    // Already a favorite - still count as success
                    $successCount++;
                }
            }
        } else {
            // Remove all found idents from favorites
            foreach ($foundIdents as $ident) {
                if (($key = array_search($ident, $preferences->sub_favorites)) !== false) {
                    unset($preferences->sub_favorites[$key]);
                    $successCount++;
                } else {
                    // Not in favorites - still count as success
                    $successCount++;
                }
            }
            // Re-index array after removals
            $preferences->sub_favorites = array_values($preferences->sub_favorites);
        }

        $user->preferences = $preferences;
        $user->save();

        $errorCount = count($notFoundIdents);
        $message = "Bulk {$action} completed: {$successCount} succeeded";
        if ($errorCount > 0) {
            $message .= ", {$errorCount} not found";
        }

        return response()->json([
            'success' => $errorCount === 0 ? 'true' : 'partial',
            'status_code' => 200,
            'message' => $message,
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'not_found' => $notFoundIdents
        ], 200);
    }

    /**
     * Execute republish for a single submission (used by bulk action)
     * Creates a new version record and marks the original as historical
     */
    private function executeSingleRepublish($submission, $user, $request)
    {
        Log::info("executeSingleRepublish called for {$submission->sid}, current status: {$submission->status}");

        // Validate submission can be republished:
        // Both published and unpublished submissions must be is_live=true
        // (the current released state for this SGC ID)
        if (!$submission->is_live) {
            throw new \Exception('Cannot republish archived submission (not the current live version)');
        }

        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

        // Check for submitted jobs - cannot create/use draft job if submitted job exists
        $hasSubmittedJob = Job::where('submitter_id', $effectiveSubmitterId)
            ->where('status', Job::STATUS_SUBMITTED)
            ->exists();

        if ($hasSubmittedJob) {
            throw new \Exception('Cannot republish submission: A submitted job is currently being processed');
        }

        // Check if there's already a draft version for this SID
        $existingDraft = Submission::where('sid', $submission->sid)
            ->whereIn('status', [
                Submission::STATUS_DRAFT_REPUBLISH,
                Submission::STATUS_SUBMITTED_REPUBLISH,
                Submission::STATUS_DRAFT_UNPUBLISH,
                Submission::STATUS_SUBMITTED_UNPUBLISH
            ])
            ->first();

        if ($existingDraft) {
            throw new \Exception("A draft version already exists for this submission ({$existingDraft->status})");
        }

        // Find or create draft job for this submitter
        $job = Job::where('submitter_id', $effectiveSubmitterId)
            ->where('status', Job::STATUS_DRAFT)
            ->first();

        if ($job === null) {
            $job = new Job([
                'user_id' => $user->id,
                'submitter_id' => $effectiveSubmitterId,
                'status' => Job::STATUS_DRAFT
            ]);
            $job->save();
            Log::info("Created new draft job {$job->slug} for bulk republishing submission {$submission->sid}");
        } else {
            Log::info("Using existing draft job {$job->slug} for bulk republishing submission {$submission->sid}");
        }

        // Calculate the next version number
        $maxVersion = Submission::where('sid', $submission->sid)->max('version_number') ?? 1;
        $newVersionNumber = $maxVersion + 1;

        // Create a NEW submission record as a copy of the original
        // This preserves the original submission unchanged
        $newSubmission = $submission->replicate(['ident']);
        $newSubmission->ident = \Illuminate\Support\Str::uuid()->toString();
        $newSubmission->version_number = $newVersionNumber;
        $newSubmission->status = Submission::STATUS_DRAFT_REPUBLISH;
        $newSubmission->job_id = $job->id;
        $newSubmission->released_at = null;
        $newSubmission->save();

        // Mark the original submission as not most recent (historical)
        $submission->is_most_recent = false;
        $submission->save();

        // Copy pubmed associations
        $pubmedIds = $submission->pubmeds()->pluck('pubmeds.id')->toArray();
        $newSubmission->pubmeds()->sync($pubmedIds);

        Log::info("Created new version {$newVersionNumber} of submission {$submission->sid} for bulk republishing");
    }

    /**
     * Execute unpublish for a single submission (used by bulk action)
     * Creates a new version record and marks the original as historical
     */
    private function executeSingleUnpublish($submission, $user, $request)
    {
        // Validate submission can be unpublished:
        // - Only published submissions can be unpublished
        // - Published submissions must be is_live=true (current publicly accessible version)
        if ($submission->status !== Submission::STATUS_PUBLISHED) {
            throw new \Exception('Only published submissions can be unpublished');
        }
        if (!$submission->is_live) {
            throw new \Exception('Cannot unpublish archived submission (not the current live version)');
        }

        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

        // Check for submitted jobs - cannot create/use draft job if submitted job exists
        $hasSubmittedJob = Job::where('submitter_id', $effectiveSubmitterId)
            ->where('status', Job::STATUS_SUBMITTED)
            ->exists();

        if ($hasSubmittedJob) {
            throw new \Exception('Cannot unpublish submission: A submitted job is currently being processed');
        }

        // Check if there's already a draft version for this SID (unpublish or republish)
        $existingDraft = Submission::where('sid', $submission->sid)
            ->whereIn('status', [
                Submission::STATUS_DRAFT_UNPUBLISH,
                Submission::STATUS_SUBMITTED_UNPUBLISH,
                Submission::STATUS_DRAFT_REPUBLISH,
                Submission::STATUS_SUBMITTED_REPUBLISH
            ])
            ->first();

        if ($existingDraft) {
            throw new \Exception("A draft version already exists for this submission ({$existingDraft->status})");
        }

        // Find or create draft job for this submitter
        $job = Job::where('submitter_id', $effectiveSubmitterId)
            ->where('status', Job::STATUS_DRAFT)
            ->first();

        if ($job === null) {
            $job = new Job([
                'user_id' => $user->id,
                'submitter_id' => $effectiveSubmitterId,
                'status' => Job::STATUS_DRAFT
            ]);
            $job->save();
            Log::info("Created new draft job {$job->slug} for bulk unpublishing submission {$submission->sid}");
        }

        // Calculate the next version number
        $maxVersion = Submission::where('sid', $submission->sid)->max('version_number') ?? 1;
        $newVersionNumber = $maxVersion + 1;

        // Create a NEW submission record as a copy of the original
        // This preserves the original submission unchanged
        $newSubmission = $submission->replicate(['ident']);
        $newSubmission->ident = \Illuminate\Support\Str::uuid()->toString();
        $newSubmission->version_number = $newVersionNumber;
        $newSubmission->status = Submission::STATUS_DRAFT_UNPUBLISH;
        $newSubmission->job_id = $job->id;
        $newSubmission->released_at = null;
        $newSubmission->save();

        // Mark the original submission as not most recent (historical)
        $submission->is_most_recent = false;
        $submission->save();

        // Copy pubmed associations
        $pubmedIds = $submission->pubmeds()->pluck('pubmeds.id')->toArray();
        $newSubmission->pubmeds()->sync($pubmedIds);

        Log::info("Created new version {$newVersionNumber} of submission {$submission->sid} for bulk unpublishing (new id: {$newSubmission->id}), marked original as not most recent");
    }

    /**
     * Export submissions to Excel using the template file
     * Preserves template formatting and appends data starting at row 13
     * Accepts SIDs and fetches data from database to avoid POST size limits
     */
    public function exportToTemplate(Request $request)
    {
        Log::info('exportToTemplate called');

        $user = Auth::user();

        if ($user === null) {
            Log::warning('exportToTemplate: No authenticated user');
            return response()->json([
                'success' => 'false',
                'status_code' => 3001,
                'message' => 'Unauthorized'
            ], 401);
        }

        Log::info('exportToTemplate: User authenticated: ' . $user->email);

        $sids = $request->input('sids');
        Log::info('exportToTemplate: Received ' . (is_array($sids) ? count($sids) : 0) . ' SIDs');

        if (empty($sids) || !is_array($sids)) {
            return response()->json([
                'success' => 'false',
                'status_code' => 3002,
                'message' => 'No submission SIDs provided'
            ], 400);
        }

        try {
            // Fetch submissions from database with relationships
            // Use the effective submitter query to respect access control
            $submissions = $this->getEffectiveSubmitterQuery($request, 'submissions')
                ->whereIn('sid', $sids)
                ->where('is_most_recent', true)
                ->with(['gene', 'disease', 'inheritance', 'classification', 'submitter'])
                ->get();

            Log::info('exportToTemplate: Found ' . $submissions->count() . ' submissions in database');

            // Transform submissions to the format expected by SubmissionsTemplateExport
            $exportData = $submissions->map(function ($submission) {
                // Convert submission_data from stdClass to array for array-style access in export
                $submissionData = json_decode(json_encode($submission->submission_data), true);

                return [
                    'sid' => $submission->sid,
                    'local_key' => $submission->local_key,
                    'submission_data' => $submissionData,
                    'gene' => [
                        'hgnc_id' => $submission->gene?->hgnc_id,
                        'symbol' => $submission->gene?->symbol,
                    ],
                    'disease' => [
                        'curie' => $submission->disease?->curie,
                        'name' => $submission->disease?->name,
                    ],
                    'inheritance' => [
                        'curie' => $submission->inheritance?->curie,
                        'name' => $submission->inheritance?->name,
                    ],
                    'classification' => [
                        'curie' => $submission->classification?->curie,
                        'name' => $submission->classification?->name,
                    ],
                    'submitter' => [
                        'curie' => $submission->submitter?->curie,
                        'name' => $submission->submitter?->name,
                    ],
                    'evidence' => $submission->evidence ?? [],
                ];
            })->toArray();

            $export = new SubmissionsTemplateExport($exportData);
            $spreadsheet = $export->generate();

            // Create writer and save to temp file (more reliable than php://output)
            $writer = new Xlsx($spreadsheet);
            $filename = 'submissions_export_' . date('Y-m-d_His') . '.xlsx';
            $tempFile = storage_path('app/temp/' . $filename);

            // Ensure temp directory exists
            if (!file_exists(storage_path('app/temp'))) {
                mkdir(storage_path('app/temp'), 0755, true);
            }

            $writer->save($tempFile);

            // Return file download response and delete after sending
            return response()->download($tempFile, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            Log::error('Export to template failed: ' . $e->getMessage());
            return response()->json([
                'success' => 'false',
                'status_code' => 500,
                'message' => 'Export failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
