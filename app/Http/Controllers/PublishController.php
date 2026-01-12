<?php

namespace App\Http\Controllers;
use Inertia\Inertia;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

use Auth;
use Log;
use Carbon\Carbon;

use App\Models\Submission;
use App\Models\Job;
use App\Models\Action;
use App\Services\JobStateMachine;
use App\Services\SubmissionStateMachine;

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
 * SubmissionController supplies the submissions data to Inertia/Vue.
 *
 * */
class PublishController extends Controller
{

    /**
     * List all the submitter submissions for the authenticated user, along with the
     * list of which ones this user has marked as favorites
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $errors = false;

        $submissions = $this->getEffectiveSubmitter($request)->submissions()->select('id', 'gene_id', 'user_id', 'disease_id', 'inheritance_id', 'classification_id', 'job_id', 'ident', 'sid', 'friendly', 'created_at', 'submission_errors', 'status', 'original_submission_data', 'submission_data', 'local_key', 'submitter_id')
            ->with('gene:id,hgnc_id,symbol')->with('disease:id,curie,name')->with('inheritance:id,curie,name')->with('classification:id,curie,name')->with('job:id')->with('submitter:id,curie,name')
            ->get();

        // double check for errors.  Eventually we'll want to record current state within the submission status
        $submissions->each(function ($item) use (&$errors) {
            if ($item->submission_errors !== null && count((array)$item->submission_errors) != 0) {
                $errors = true;
                return false;
            }
        });

        // Debug: Check if original_submission_data is present
        $sgc124139 = $submissions->firstWhere('sid', 'SGC-124139');
        if ($sgc124139) {
            \Log::info('Submissions page - SGC-124139', [
                'has_original_data' => isset($sgc124139->original_submission_data),
                'original_disease' => $sgc124139->original_submission_data->disease ?? 'NOT FOUND',
                'toArray_has_original' => isset($sgc124139->toArray()['original_submission_data'])
            ]);
        }

        return Inertia::render('Submissions', [
            'submissions' => $submissions,
            'errors' => $errors,
            'favorites' => Auth::user()->preferences->sub_favorites ?? []
        ]);
    }


    /**
     * Initialize a new publishing session
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function init(Request $request)
    {

        $server = env('PUBLISH_URL', false);

        $token = env('GENCC_PUBLISH_TOKEN');

        // send init handshake
        $data = [
            'token' => $token,
            'timestamp' => Carbon::now()
        ];

        $template = view('json.Publish.init')->with($data);

        // Verbose logging removed to prevent memory issues
        // echo "\nIn init: " . $template->render();

        // post packet TODO:  Need a new key assignment
        try {
            $response = Http::withHeaders([
                'GEN-API-KEY' => $token
            ])->withBody(
                $template->render(), 'application/json; charset=UTF-8'
            )->post($server);

            if ($response->successful()) {
                return $response->json();
            } else {
                \Log::error('Publish init failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return ['status_code' => $response->status(), 'error' => 'HTTP error'];
            }
        } catch (\Exception $e) {
            \Log::error('Publish init exception', ['error' => $e->getMessage()]);
            return ['status_code' => 500, 'error' => $e->getMessage()];
        }
    }


    /**
     * Publish submissions for a given job
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function send_job(Request $request, $job)
    {

        $server = env('PUBLISH_URL', false);

        $token = env('GENCC_PUBLISH_TOKEN');

        // send init handshake
        $data = [
            'token' => $token,
            'timestamp' => Carbon::now(),
            'job' => $job
        ];

        $successfulSubmissions = []; // Track successfully processed submissions with their action
        $failedSubmissions = [];     // Track failed submissions with error details

        foreach ($job->submissions as $submission) {
            // Skip submissions that are already in a final state (published or unpublished)
            if (in_array($submission->status, [Submission::STATUS_PUBLISHED, Submission::STATUS_UNPUBLISHED])) {
                continue;
            }

            // Skip legacy published submissions (only for migrations - all submissions should have status set)
            if (!$submission->status) {
                \Log::warning("Submission {$submission->sid} has no status - skipping");
                continue;
            }

            // Determine action_type for gencc-search API based on submission state
            // Both new and republish use "publish", unpublish uses "unpublish"
            // With simplified status model: new/republish/unpublish are pending statuses
            $action_type = match($submission->status) {
                Submission::STATUS_NEW => 'publish',
                Submission::STATUS_REPUBLISH => 'publish',
                Submission::STATUS_UNPUBLISH => 'unpublish',
                default => 'publish' // fallback for legacy
            };

            // Add action_type to data array for template
            $submissionData = array_merge($data, ['action_type' => $action_type]);

            $response = $this->_publish_submission($submission, 'json.Publish.submission', $submissionData, $token, $server);

            if (isset($response['status_code']) && $response['status_code'] == 200) {
                // Determine target state and action based on current state
                // new/republish -> published, unpublish -> unpublished
                $targetState = match($submission->status) {
                    Submission::STATUS_NEW => Submission::STATUS_PUBLISHED,
                    Submission::STATUS_REPUBLISH => Submission::STATUS_PUBLISHED,
                    Submission::STATUS_UNPUBLISH => Submission::STATUS_UNPUBLISHED,
                    default => null
                };

                // Determine action type for tracking
                $action = match($submission->status) {
                    Submission::STATUS_NEW => 'published',
                    Submission::STATUS_REPUBLISH => 'republished',
                    Submission::STATUS_UNPUBLISH => 'unpublished',
                    default => 'published' // fallback for legacy
                };

                if ($targetState) {
                    // Use state machine for V2 submissions
                    SubmissionStateMachine::transition($submission, $targetState);

                    // Update released_at timestamp and snapshot for published submissions
                    if ($targetState === Submission::STATUS_PUBLISHED) {
                        $submission->released_at = Carbon::now();
                        $submission->original_submission_data = $submission->submission_data;

                        // Mark all previous versions of this SID as:
                        // - not most recent (version ordering)
                        // - not live (no longer publicly accessible - archived)
                        Submission::where('sid', $submission->sid)
                            ->where('id', '!=', $submission->id)
                            ->update([
                                'is_most_recent' => false,
                                'is_live' => false
                            ]);

                        // Mark this version as most recent and live (publicly accessible)
                        $submission->is_most_recent = true;
                        $submission->is_live = true;
                    }

                    // When unpublishing, this submission becomes the most recent version
                    // and IS live (the "hidden" state IS the current public state for this SGC ID)
                    if ($targetState === Submission::STATUS_UNPUBLISHED) {
                        // Mark all OTHER versions of this SID as not most recent and not live (archived)
                        Submission::where('sid', $submission->sid)
                            ->where('id', '!=', $submission->id)
                            ->update([
                                'is_most_recent' => false,
                                'is_live' => false
                            ]);

                        // This unpublished version IS the most recent version AND is live
                        // because the "hidden" state represents the current public state of this SGC ID
                        $submission->is_most_recent = true;
                        $submission->is_live = true;
                        $submission->unpublished_at = Carbon::now();
                    }

                    $submission->save();
                } else {
                    // Fallback for legacy submissions without status
                    // Just update timestamps
                    $submission->update([
                        'released_at' => Carbon::now(),
                        'original_submission_data' => $submission->submission_data
                    ]);
                }

                // Track this submission as successfully processed with its action type and classification
                $successfulSubmissions[] = [
                    'sid' => $submission->sid,
                    'display_id' => $submission->display_id,
                    'action' => $action,
                    'classification_id' => $submission->classification_id
                ];

                // add something to the workflow and to the history?
            } else {
                // Capture error details for failed submission
                // Log full response to debug error message extraction
                \Log::info("Failed submission response", [
                    'sid' => $submission->sid,
                    'response' => $response
                ]);

                // Try to get the most detailed error message available
                $errorMessage = $response['message'] ?? $response['error'] ?? 'Unknown error';

                $failedSubmissions[] = [
                    'submission' => $submission,
                    'error_code' => $response['status_code'] ?? 'unknown',
                    'error_message' => $errorMessage,
                    'error_details' => $response['error_response'] ?? null
                ];
            }
        }

        // Handle failed submissions if any exist
        if (!empty($failedSubmissions)) {
            $this->handleFailedSubmissions($job, $failedSubmissions);
        }

        // Save the list of ALL submitted submissions (both successful and failed)
        $allSubmittedEntries = [];

        // Add successful submissions
        foreach ($successfulSubmissions as $entry) {
            $allSubmittedEntries[] = $entry;
        }

        // Add failed submissions with action='error'
        foreach ($failedSubmissions as $failure) {
            $allSubmittedEntries[] = [
                'sid' => $failure['submission']->sid,
                'display_id' => $failure['submission']->display_id,
                'action' => 'error'
            ];
        }

        if (!empty($allSubmittedEntries)) {
            // Get existing processed submissions and merge with new ones
            $existingProcessed = $job->processed_submission_ids ?? [];

            // Merge new submissions with existing, avoiding duplicates
            // Keep the most recent action if a SID appears multiple times
            $allProcessed = $existingProcessed;
            foreach ($allSubmittedEntries as $newEntry) {
                // Check if this SID already exists
                $existingIndex = array_search($newEntry['sid'], array_column($allProcessed, 'sid'));
                if ($existingIndex !== false) {
                    // Update existing entry with new action
                    $allProcessed[$existingIndex] = $newEntry;
                } else {
                    // Add new entry
                    $allProcessed[] = $newEntry;
                }
            }

            $job->processed_submission_ids = $allProcessed;
        }

        // ALWAYS mark the submitted job as processed
        if ($job->status) {
            JobStateMachine::complete($job);
            $job->save();
        } else {
            // Fallback for legacy jobs
            $job->update(['status' => Job::STATUS_PROCESSED]);
        }

        // Return response with success/failure counts
        $successCount = count($successfulSubmissions);
        $failCount = count($failedSubmissions);

        if ($failCount > 0) {
            return response()->json([
                'success' => 'partial',
                'status_code' => 200, // Still 200 because job was processed
                'message' => "Job processed: {$successCount} succeeded, {$failCount} failed and moved to draft",
                'success_count' => $successCount,
                'failed_count' => $failCount
            ], 200);
        }

        return response()->json([
            'success' => 'true',
            'status_code' => 200,
            'message' => "Job successfully completed",
            'success_count' => $successCount,
            'failed_count' => 0
        ], 200);
    }

    /**
     * Perform the given action
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function send_action(Request $request, $action)
    {

        $server = env('PUBLISH_URL', false);

        $token = env('GENCC_PUBLISH_TOKEN');

        // send init handshake
        $data = [
            'token' => $token,
            'timestamp' => Carbon::now(),
            'action' => $action
        ];

        $success = true;

        $template = view('json.Publish.unpublish')->with($data);

        // Verbose logging removed to prevent memory issues
        // var_dump($template->render());

        // post packet TODO:  Need a new key assignment
        $response = Http::withHeaders([
            'GEN-API-KEY' => $token
        ])->withBody(
            $template->render(), 'application/json; charset=UTF-8'
        )->post($server);

        $response = $response->json();

        if (isset($response['status_code']) && $response['status_code'] == 200) {
            // mark action as completed
            $action->update(['status' => Action::STATUS_COMPLETE]);

            Log::info("Successful send action!");

            // return success
            return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => "Action successfully completed"],
                200);
        }

        // return with the proper rstatus code.
        Log::info("Unsuccessful send action!");

        return response()->json(['success' => 'false',
            'status_code' => 1002,
            'message' => "Action failed to complete"],
            501);
    }

    public function send_sgc_id(Request $request, $submission)
    {

        $server = env('PUBLISH_URL', false);

        $token = env('GENCC_PUBLISH_TOKEN');

        $data = [
            'token' => $token,
            'timestamp' => Carbon::now(),
            'action' => 'sgc_id'
        ];

        $response = $this->_publish_submission($submission, 'json.Publish.sgc_id', $data, $token, $server);

        return $response;
    }

    /**
     * Close out the current publishing session
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function end(Request $request)
    {

        $server = env('PUBLISH_URL', false);

        $token = env('GENCC_PUBLISH_TOKEN');

        // send init handshake
        $data = [
            'token' => $token,
            'timestamp' => Carbon::now()
        ];

        $template = view('json.Publish.end')->with($data);

        // Verbose logging removed to prevent memory issues
        // echo "In end" . $template->render();

        // post packet TODO:  Need a new key assignment
        $response = Http::withHeaders([
            'GEN-API-KEY' => $token
        ])->withBody(
            $template->render(), 'application/json; charset=UTF-8'
        )->post($server);

        return $response->json();
    }

    /**
     * Trigger update_counts on gencc-search to refresh classification counts
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function updateCounts(Request $request)
    {
        $server = env('PUBLISH_URL', false);

        $token = env('GENCC_PUBLISH_TOKEN');

        $data = [
            'token' => $token,
            'timestamp' => Carbon::now()
        ];

        $template = view('json.Publish.update_counts')->with($data);

        try {
            $response = Http::withHeaders([
                'GEN-API-KEY' => $token
            ])->withBody(
                $template->render(), 'application/json; charset=UTF-8'
            )->post($server);

            if ($response->successful()) {
                return $response->json();
            } else {
                \Log::error('Update counts failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return ['status_code' => $response->status(), 'error' => 'HTTP error'];
            }
        } catch (\Exception $e) {
            \Log::error('Update counts exception', ['error' => $e->getMessage()]);
            return ['status_code' => 500, 'error' => $e->getMessage()];
        }
    }

    private function _publish_submission($submission, $template, $header_data, $token, $server) {

        $template = view($template)->with($header_data)
            ->with('submission', $submission);

        $rendered = $template->render();

        // Log the request payload for debugging
        \Log::info('Publishing submission', [
            'sid' => $submission->sid,
            'payload' => $rendered
        ]);

        $response = Http::withHeaders([
            'GEN-API-KEY' => $token
        ])->withBody(
            $rendered, 'application/json; charset=UTF-8'
        )->post($server);

        if ($response->successful()) {
            return $response->json();
        } else {
            // Parse error response from gencc-search
            $errorBody = $response->body();
            $errorData = null;

            try {
                $errorData = json_decode($errorBody, true);
            } catch (\Exception $e) {
                // Body might not be JSON
            }

            \Log::error('Publish submission failed', [
                'status' => $response->status(),
                'submission_id' => $submission->sid ?? 'unknown',
                'error_response' => $errorData ?? $errorBody,
                'response_length' => strlen($errorBody)
            ]);

            // Also output to console during run:publish
            echo "\n[ERROR] Submission {$submission->sid} failed with status {$response->status()}\n";
            if ($errorData) {
                echo "Error details: " . json_encode($errorData, JSON_PRETTY_PRINT) . "\n";
            } else {
                echo "Error response: " . substr($errorBody, 0, 500) . "\n";
            }

            // Return the full error data so it can be properly parsed and assigned to fields
            return $errorData ?? ['status_code' => $response->status(), 'error' => 'HTTP error'];
        }
    }

    /**
     * Handle submissions that failed during publish
     * Moves them to a draft job (existing or new) for correction
     *
     * @param Job $originalJob The submitted job being processed
     * @param array $failedSubmissions Array of failed submissions with error details
     * @return void
     */
    private function handleFailedSubmissions(Job $originalJob, array $failedSubmissions)
    {
        // Get or create draft job for this user
        $draftJob = $this->getOrCreateDraftJob($originalJob->user_id, $originalJob->submitter_id);

        foreach ($failedSubmissions as $failure) {
            $submission = $failure['submission'];

            // Parse error message to determine which field caused the error
            $errorMessage = $failure['error_message'];
            $errorField = $this->parseErrorField($errorMessage);

            // Store error in submission_errors JSON field with the correct field key
            // submission_errors is stored as stdClass in Laravel, so cast to array
            $errors = (array)($submission->submission_errors ?? []);
            $errors[$errorField] = $errorMessage;

            $submission->submission_errors = (object)$errors;

            // With simplified status model, status does NOT change when moving back to draft job
            // Stage (draft/submitted) is derived from Job.status
            // Just move the submission to the draft job - no status transition needed

            // Move to draft job
            $submission->job_id = $draftJob->id;
            $submission->save();

            // Log the failure
            \Log::warning("Submission {$submission->sid} failed publish and moved to draft job {$draftJob->slug}", [
                'error' => $errorMessage,
                'error_field' => $errorField,
                'original_job' => $originalJob->slug
            ]);
        }
    }

    /**
     * Parse error message to determine which field caused the error
     * Maps error messages to the correct submission_errors field keys
     *
     * @param string $errorMessage
     * @return string The field key for submission_errors
     */
    private function parseErrorField(string $errorMessage): string
    {
        // Check for disease-related errors
        if (stripos($errorMessage, 'Disease not found') !== false ||
            stripos($errorMessage, 'disease') !== false && stripos($errorMessage, 'MONDO') !== false) {
            return 'disease_curie_id';
        }

        // Check for gene-related errors
        if (stripos($errorMessage, 'Gene not found') !== false ||
            stripos($errorMessage, 'gene') !== false && stripos($errorMessage, 'HGNC') !== false) {
            return 'gene_hgnc_id';
        }

        // Check for MOI-related errors
        if (stripos($errorMessage, 'MOI not found') !== false ||
            stripos($errorMessage, 'inheritance') !== false ||
            stripos($errorMessage, 'HP:') !== false) {
            return 'moi_curie_id';
        }

        // Check for classification-related errors
        if (stripos($errorMessage, 'Classification not found') !== false ||
            stripos($errorMessage, 'classification') !== false) {
            return 'classification_curie_id';
        }

        // Check for PMID-related errors
        if (stripos($errorMessage, 'PMID') !== false ||
            stripos($errorMessage, 'PubMed') !== false) {
            return 'invalid_pmid';
        }

        // Check for date-related errors
        if (stripos($errorMessage, 'date') !== false) {
            return 'report_date';
        }

        // Check for URL-related errors
        if (stripos($errorMessage, 'URL') !== false) {
            return 'report_url';
        }

        // Default to generic publish_error if we can't determine the specific field
        return 'publish_error';
    }

    /**
     * Get existing draft job for user, or create a new one
     *
     * @param int $userId
     * @param int $submitterId
     * @return Job
     */
    private function getOrCreateDraftJob(int $userId, int $submitterId): Job
    {
        // Check for existing draft job for this user
        $draftJob = Job::where('user_id', $userId)
            ->where('status', Job::STATUS_DRAFT)
            ->first();

        if ($draftJob) {
            return $draftJob;
        }

        // Create new draft job
        $newJob = new Job();
        $newJob->user_id = $userId;
        $newJob->submitter_id = $submitterId;
        $newJob->status = Job::STATUS_DRAFT;
        // created_at is auto-set by Laravel
        $newJob->save();

        // Slug will be auto-generated by model event

        \Log::info("Created new draft job {$newJob->slug} for failed submissions", [
            'user_id' => $userId,
            'submitter_id' => $submitterId
        ]);

        return $newJob;
    }
}
