<?php

namespace App\Http\Controllers;
use Inertia\Inertia;

use Illuminate\Http\Request;

use Auth;

use App\Models\Alias;
use App\Models\Submitter;
use App\Services\SubmissionDuplicateDetection;
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
class SubmissionController extends Controller
{

    /**
     * List all the submitter submissions for the authenticated user, along with the
     * list of which ones this user has marked as favorites
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $errors = false;

        $submissions = $this->getEffectiveSubmitter($request)->submissions()->forListing()->get();

        // dd($submissions);

        // double check for errors.  Eventually we'll want to record current state within the submission status
        $submissions->each(function ($item) use (&$errors) {
            if ($item->submission_errors !== null && count( (array) $item->submission_errors) != 0)
            {
                $errors = true;
                return false;
            }
        });

        // Check if there are any submitted jobs - used to disable edit/unpublish buttons
        $hasSubmittedJob = $this->getEffectiveSubmitter($request)
            ->jobs()
            ->where('status', \App\Models\Job::STATUS_SUBMITTED)
            ->exists();

        return Inertia::render('Submissions', [
            'submissions' => $submissions,
            'errors' => $errors,
            'favorites' => Auth::user()->preferences->sub_favorites ?? [],
            'hasSubmittedJob' => $hasSubmittedJob
        ]);
    }


    /**
     * Show a particular submission for the authenticated user
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id)
    {
        $submission = $this->getEffectiveSubmitterQuery($request, 'submissions')
                        ->with('gene')->with('user')->with('disease')->with('originalDisease')->with('inheritance')->with('submitter')->with('classification')->with('job')->with('pubmeds')->with('mechanism')->with('lastEditedByUser.teams')
                        ->where('ident', $id)->first();

        if (!$submission) {
            abort(404, 'Submission not found');
        }

        \Log::info('SubmissionController@show: Loading submission', [
            'sid' => $submission->sid,
            'status' => $submission->status,
            'has_gene' => !is_null($submission->gene),
            'has_disease' => !is_null($submission->disease),
            'has_job' => !is_null($submission->job),
            'submission_data_keys' => is_object($submission->submission_data) ? array_keys((array)$submission->submission_data) : 'not_object'
        ]);

        $criteria_options = $this->getEffectiveSubmitterQuery($request, 'aliases')->type(Alias::TYPE_CRITERIA)->get();

        // Check if there are any submitted jobs - used to disable edit/unpublish buttons
        $hasSubmittedJob = $this->getEffectiveSubmitter($request)
            ->jobs()
            ->where('status', \App\Models\Job::STATUS_SUBMITTED)
            ->exists();

        // Check for unpublished duplicates (only for non-unpublished submissions)
        $unpublishedDuplicateWarning = null;
        if ($submission->status !== 'unpublished' &&
            $submission->gene_id &&
            $submission->original_disease_id &&
            $submission->inheritance_id) {

            $duplicateResult = SubmissionDuplicateDetection::checkForDuplicates(
                $submission->submitter_id,
                $submission->gene_id,
                $submission->original_disease_id,
                $submission->inheritance_id,
                $submission->id  // Exclude self
            );

            if ($duplicateResult['has_unpublished_duplicate']) {
                $unpublishedDuplicateWarning = [
                    'type' => 'unpublished_duplicate',
                    'sgc_ids' => $duplicateResult['unpublished_duplicates']->pluck('sid')->toArray(),
                    'message' => SubmissionDuplicateDetection::formatUnpublishedWarningMessage(
                        $duplicateResult['unpublished_duplicates']
                    )
                ];
            }
        }

        return Inertia::render('Submission', [
            'submission' => $submission,
            'criterias' => $criteria_options,
            'hasSubmittedJob' => $hasSubmittedJob,
            'unpublishedDuplicateWarning' => $unpublishedDuplicateWarning
        ]);
    }
}
