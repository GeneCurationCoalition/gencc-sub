<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use App\Models\Submission;
use App\Models\User;
use App\Models\Submitter;
use App\Models\Job;
use App\Models\Pubmed;

use Carbon\Carbon;

class SubmitController extends Controller
{
    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request, $action = false)
    {
        $content = $request->getContent();

        // check the authentication token
        $api_token = $request->header('GEN-API-KEY');

        if (empty($api_token))
            return response()->json(['success' => 'false',
								 'status_code' => 9001,
							 	 'message' => 'Unauthorized'],
                                  401);

        $user = User::api($api_token)->active()->first();
        if($user === null)
            return response()->json(['success' => 'false',
								 'status_code' => 9002,
							 	 'message' => 'Unauthorized'],
                                  401);

        // a quick validation of the incoming schema
        if (!json_validate($content))
        {
            $msg =  json_last_error_msg();
            return response()->json(['success' => 'false',
								 'status_code' => 1001,
							 	 'message' => $msg],
                                  501);
        }

        // TODO:  use a json schema validator

        // ok, decode the content
		$packet = json_decode($content, false, 100);
        if (empty($packet))
        {
            return response()->json(['success' => 'false',
								 'status_code' => 1002,
							 	 'message' => "Json schema error"],
                                  501);
        }

        // validate the user is associated submitter institution
        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);
        $submitter = Submitter::curie($packet->submitter->id)->first();
        if ($submitter === null || $submitter->id != $effectiveSubmitterId)
            return response()->json(['success' => 'false',
								 'status_code' => 9003,
							 	 'message' => 'Unauthorized'],
                                  401);


        // verify the action is valid
        if (!in_array($packet->action, ['create', 'check']))
            return response()->json(['success' => 'false',
                'status_code' => 1003,
                'message' => "Unknown action"],
                501);

        // if this is only a dry run, finish up and return
        if ($action == "check" || $packet->action == "check")
        {
            return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => "OK"],
                200);
        }

        // created_at is auto-set by Laravel when saving

        // build a new job entry
        // TODO: LEGACY STATUS - Migrate to V2 status in Phase 2
        // Should use: 'status' => Job::STATUS_DRAFT
        // See: LEGACY_STATUS_MIGRATION.md
        $job = Job::create([
                    'type' => Job::TYPE_API_SUBMISSION,
                    'user_id' => $user->id,
                    'submitter_id' => $submitter->id,
                    // created_at is auto-set by Laravel
                    'submission_data' => $packet,
                    'status' => Job::STATUS_PROCESSING
        ]);

        // process the packet
        switch ($packet->action)
        {
            case "create":
                foreach($packet->data as $data)
                {
                    switch ($data->action)
                    {
                        case "new":
                            $submission = new Submission();
                            $submission->submitter_id = $submitter->id;
                            break;
                        case "update":
                            $submission = $submitter->submissions()->where('sid', data->submission_id)->first();
                            if ($submission === null)
                                continue 2;
                            break;
                        default:
                            continue 2;
                    }
                    $status = $submission->load_from_json($data);
                    if ($status === true)
                    {
                        $submission->user_id = $user->id;
                        $job->submissions()->save($submission);
                    }
                    else
                    {
                        $job->addEvent('SID $submision->sid encountered submission errors');
                        $job->update(['status' => Job::STATUS_ERRORS]);
                        $submission->submission_errors = $status;
                        // Errors are determined by submission_errors via has_errors accessor
                        $job->submissions()->save($submission);
                        
                        /*return response()->json(['success' => 'false',
                            'status_code' => 1004,
                            'message' => $status],
                            501);*/
                    }

                    // we can now update the evidence pivot table entries
                    $submission->pubmeds()->detach();

                    foreach ($submission->evidence as $evidence)
                    {
                        $pubmed = Pubmed::where('pmid', $evidence)->first();
                        
                        if ($pubmed === null)
                            continue;

                        $submission->pubmeds()->attach($pubmed->id);
                    }
                }

                // TODO: LEGACY STATUS - Migrate to V2 status in Phase 2
                // Should use: JobStateMachine::submit($job)
                // See: LEGACY_STATUS_MIGRATION.md
                // add status check back to STAGED
                IF($job->status == Job::STATUS_PROCESSING)
                {
                    $job->update(['status' => Job::STATUS_STAGED]);
                }
                break;
            default:
                return response()->json(['success' => 'false',
                    'status_code' => 1003,
                    'message' => "Unknown action"],
                    501);
        }
		
        // send 200 response
        $data = [
            'timestamp' => Carbon::now(),
            'message' => "Job accepted",
            'id' => $job->slug
        ];

        $response = \View::make('json.submission_response_ok')->with($data);
		return \Response::make($response, '200')->header('Content-Type', 'application/json');
    }


    /**
     * Query the details of a job or individual submission
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, $id = null)
    {
        if ($id === null)
        {
            return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => "Nothing to do"],
                200);
        }

        // check the authentication token
        $api_token = $request->header('GEN-API-KEY');

        if (empty($api_token))
            return response()->json(['success' => 'false',
								 'status_code' => 9000,
							 	 'message' => 'Unauthorized'],
                                  401);

        $user = User::api($api_token)->active()->first();
        if($user === null)
            return response()->json(['success' => 'false',
								 'status_code' => 9005,
							 	 'message' => 'Unauthorized'],
                                  401);

        // is this a ob or submission request?
        if (strpos(url()->current(), '/submit/job/'))
        {

            $job = Job::where('slug', $id)->first();

            if ($job === null || $job->user_id != $user->id)
                return response()->json(['success' => 'false',
                                'status_code' => 9006,
                                'message' => 'Unauthorized'],
                                401);

            
            $response = view('json.job_query')->with('job', $job)->with('submissions', $job->submissions);
            return \Response::make($response, '200')->header('Content-Type', 'application/json');
        }

        // ok, not a job then
        $submission = Submission::sid($id)->first();

        if ($submission === null || $submission->user_id != $user->id)
                return response()->json(['success' => 'false',
                                'status_code' => 9007,
                                'message' => 'Unauthorized'],
                                401);
        
        $response = view('json.submission_query')->with('job', $submission->job)->with('submission', $submission);
        return \Response::make($response, '200')->header('Content-Type', 'application/json');
                
        // should never get here, but just in case...
        return response()->json(['success' => 'false',
								 'status_code' => 1002,
							 	 'message' => "Id not found"],
                                  501);
    }


    /**
     * Query the details of a job or individual submission
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, $id = null)
    {
        if ($id === null)
        {
            return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => "Nothing to do"],
                200);
        }

        // check the authentication token
        $api_token = $request->header('GEN-API-KEY');

        if (empty($api_token))
            return response()->json(['success' => 'false',
								 'status_code' => 9000,
							 	 'message' => 'Unauthorized'],
                                  401);

        $user = User::api($api_token)->active()->first();
        if($user === null)
            return response()->json(['success' => 'false',
								 'status_code' => 9005,
							 	 'message' => 'Unauthorized'],
                                  401);

        // is this a job or submission request?
        if (strpos(url()->current(), '/remove/job/'))
        {
            $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);
            $job = Job::where('slug', $id)->first();

            if ($job === null || $job->submitter_id != $effectiveSubmitterId)
                return response()->json(['success' => 'false',
                                'status_code' => 9006,
                                'message' => 'Unauthorized'],
                                401);

            // Remove all the submissions using soft delete
            // Legacy status field is deprecated - soft delete handles removal tracking
            $job->submissions()->delete();

            // now remove the job
            $job->update(['status' => Job::STATUS_REMOVED]);
            $job->delete();

            return response()->json(['success' => 'true',
                                'status_code' => 200,
                                'message' => "Job Removed"],
                                200);
        }

        // if this is a sid, just process that submission
        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);
        $submission = Submission::sid($id)->first();

        if ($submission === null || $submission->submitter_id != $effectiveSubmitterId)
            return response()->json(['success' => 'false',
                                'status_code' => 9007,
                                'message' => 'Unauthorized'],
                                401);

        // Remove submission using soft delete - legacy status field is deprecated
        $submission->delete();

        return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => "Submission Removed"],
                200);
    }


     /**
     * Query the status of a job or individual submission
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function status(Request $request, $id = null)
    {
        if ($id === null)
        {
            return response()->json(['success' => 'true',
                'status_code' => 200,
                'message' => "Nothing to do"],
                200);
        }

        // check the authentication token
        $api_token = $request->header('GEN-API-KEY');

        if (empty($api_token))
            return response()->json(['success' => 'false',
								 'status_code' => 9000,
							 	 'message' => 'Unauthorized'],
                                  401);

        $user = User::api($api_token)->active()->first();
        if($user === null)
            return response()->json(['success' => 'false',
								 'status_code' => 9005,
							 	 'message' => 'Unauthorized'],
                                  401);

        // is this a job or submission request?
        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);

        if (strpos(url()->current(), '/status/job/'))
        {
            $job = Job::where('slug', $id)->first();

            if ($job === null || $job->submitter_id != $effectiveSubmitterId)
                return response()->json(['success' => 'false',
                                'status_code' => 9006,
                                'message' => 'Unauthorized'],
                                401);

            // send 200 response
            $data = [
                'timestamp' => Carbon::now(),
                'message' => "Status Response",
                'id' => $job->slug,
                'job_message' => $job->json_display_status
            ];

            $response = \View::make('json.status_ok')->with($data);
            return \Response::make($response, '200')->header('Content-Type', 'application/json');
        }

        // if this is a sid, just process that submission
        $submission = Submission::sid($id)->first();

        if ($submission === null || $submission->submitter_id != $effectiveSubmitterId)
            return response()->json(['success' => 'false',
                                'status_code' => 9007,
                                'message' => 'Unauthorized'],
                                401);

        // send 200 response
        $data = [
            'timestamp' => Carbon::now(),
            'message' => "Status Response",
            'id' => $submission->job->slug,
            'sid' => $submission->sid,
            'job_message' => $submission->job->json_display_status,
            'submission_message' => $submission->json_display_status
        ];

        $response = \View::make('json.submission_status_ok')->with($data);
        return \Response::make($response, '200')->header('Content-Type', 'application/json');
                
        // should never get here, but just in case...
        return response()->json(['success' => 'false',
                                    'status_code' => 1002,
                                    'message' => "Id not found"],
                                    501);
    }




    /**
     * GenCC-search simulator for testing.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function pubrec(Request $request)
    {
        $reject = false;

        $ptoken = env('GENCC_PUBLISH_TOKEN');

        // confirm token
        if ($ptoken === false || $request->input('token') != $ptoken)
                    return response()->json(['success' => 'false',
                                'status_code' => 9001,
                                'message' => 'No auth'],
                                501);

        if ($reject)
                    return response()->json(['success' => 'false',
                                'status_code' => 9002,
                                'message' => 'Service not available'],
                                501);

        switch ($request->input('action'))
        {
            case 'init':
                // respond
                return response()->json(['success' => 'true',
                            'status_code' => 200,
                            'sid' => $request->input('action'),
                            'message' => 'Ready for jobs'],
                            200);
                break;
            case 'publish':
                // add submission to the db
                $data = $request->input('data');

                // respond
                return response()->json(['success' => 'true',
                            'status_code' => 200,
                            'sid' => $data['submission_id'],
                            'message' => 'Submission accepted'],
                            200);
                break;
            case 'end':
                // initiate recount

                // respond
                return response()->json(['success' => 'true',
                            'status_code' => 200,
                            'message' => 'Session complete'],
                            200);
                break;
            default:
                break;
        }

        return response()->json(['success' => 'false',
                'status_code' => 9011,
                'message' => 'Unknown command'],
                200);
    }

}
