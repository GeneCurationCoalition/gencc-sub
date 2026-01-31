<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\API\SubmitController;
use App\Http\Controllers\API\DiseaseController;
use App\Http\Controllers\API\SubmissionController;
use App\Http\Controllers\API\GeneController;
use App\Http\Controllers\API\UserController;
use App\Http\Controllers\API\JobController;
use App\Http\Controllers\API\AliasController;
use App\Http\Controllers\API\DocumentController;
use App\Http\Controllers\API\PubmedController;
use App\Http\Controllers\API\AdminController;
use App\Http\Controllers\API\SubmitterController;
use App\Http\Controllers\API\TeamController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

/**
 * Main submission endpoint.  The optional check parameter tells the system to 
 * check the submission for any errors, but do not process it.
 */
Route::post('/submit/{action?}', [SubmitController::class, 'store']);


/**
 * Main query endpoints.
 */
Route::get('/submit/{id}', [SubmitController::class, 'show']);

Route::get('/submit/job/{id}', [SubmitController::class, 'show']);

Route::get('/query/{id}', [SubmitController::class, 'show']);

Route::get('/query/job/{id}', [SubmitController::class, 'show']);



// endpoint for removing a submission
Route::get('/submit/{id}/remove', [SubmitController::class, 'destroy']);

Route::get('/submit/job/{id}/remove', [SubmitController::class, 'destroy']);

Route::get('/remove/{id}', [SubmitController::class, 'destroy']);

Route::get('/remove/job/{id}', [SubmitController::class, 'destroy']);


// endpoint for checking status of a submission
Route::get('/submit/{id}/status', [SubmitController::class, 'status']);

Route::get('/submit/job/{id}/status', [SubmitController::class, 'status']);

Route::get('/status/{id}', [SubmitController::class, 'status']);

Route::get('/status/job/{id}', [SubmitController::class, 'status']);


/**
 * Various calls for validating and updating the submission relations
 * 
 */
Route::group(['middleware' => ['web']], function () {

    // endpoint for checking on a disease
    Route::get('/lookup/disease/{id}', [DiseaseController::class, 'show']);

    // endpoint for checking on a gene
    Route::get('/lookup/gene/{id}', [GeneController::class, 'show']);

    // endpoint for creating a submission
    Route::post('/submissions/create', [SubmissionController::class, 'store']);

    // endpoint for bulk actions on multiple submissions (must be before {id} routes)
    Route::post('/submissions/bulk-action', [SubmissionController::class, 'bulkAction']);

    // endpoint for bulk favorite/unfavorite operations (handles all in single request)
    Route::post('/submissions/bulk-favorites', [SubmissionController::class, 'bulkFavorites']);

    // endpoint for exporting submissions to Excel template
    Route::post('/submissions/export-template', [SubmissionController::class, 'exportToTemplate']);

    // endpoint for updating a submission
    Route::post('/submissions/{id}', [SubmissionController::class, 'update']);

    // endpoint for removing a submission
    Route::delete('/submissions/{id}', [SubmissionController::class, 'destroy']);

    // endpoint for canceling a submission (V2 state transitions)
    Route::post('/submissions/{id}/cancel', [SubmissionController::class, 'cancel']);

    // endpoint for republishing a submission (V2 state transitions)
    Route::post('/submissions/{id}/republish', [SubmissionController::class, 'republish']);

    // endpoint for unpublishing a submission (V2 state transitions)
    Route::post('/submissions/{id}/unpublish', [SubmissionController::class, 'unpublish']);

    // endpoint for removing a job
    Route::delete('/jobs/{id}', [JobController::class, 'destroy']);

    // endpoint for creating a job
    Route::get('/jobs/create', [JobController::class, 'store']);

    // endpoint for publishing a job
    Route::get('/jobs/publish/{id}', [JobController::class, 'publish']);

    // endpoint for unpublishing a job
    Route::get('/jobs/unpublish/{id}', [JobController::class, 'unpublish']);

    // endpoint for submitting a job (V2 state machine)
    Route::post('/jobs/submit/{id}', [JobController::class, 'submit']);

    // endpoint for job updates
    Route::post('/jobs/{id}', [JobController::class, 'update_name']);

    // endpoint for profile updates
    Route::post('/users/{id}', [UserController::class, 'update']);

    // endpoint for submitter updates
    Route::post('/submitters/{id}', [SubmitterController::class, 'update']);

    // endpoint for team updates
    Route::post('/teams/{id}', [TeamController::class, 'update']);
    Route::post('/teams/{id}/members', [TeamController::class, 'addMember']);
    Route::delete('/teams/{id}/members/{userId}', [TeamController::class, 'removeMember']);

    // endpoint for alias updates
    Route::post('/aliases/{id}', [AliasController::class, 'update']);

    // endpoint for alias updates
    Route::delete('/aliases/{id}', [AliasController::class, 'destroy']);

    // endpoint for document uploads
    Route::post('/documents/{id}', [DocumentController::class, 'store']);

    // endpoint for processing a validated document
    Route::post('/documents/{id}/process', [DocumentController::class, 'processValidated']);

    // endpoint for fetching document processing errors as JSON
    Route::get('/documents/{id}/errors', [DocumentController::class, 'get_errors']);

    // endpoint for downloading a document
    Route::get('/documents/{ident}/download', [DocumentController::class, 'download']);

    // endpoint for polling validation progress (id is job ident, not document id)
    Route::get('/jobs/{id}/progress', [DocumentController::class, 'get_progress']);

    // endpoint for polling upload progress during async file upload
    Route::get('/jobs/{id}/upload-progress', [\App\Http\Controllers\JobController::class, 'uploadProgress']);

    // endpoint for clearing a document from a job (invalid files - preserves submissions)
    Route::delete('/documents/{id}', [DocumentController::class, 'clearDocument']);

    // endpoint for clearing a valid document and handling submissions
    Route::delete('/documents/{id}/clear-valid', [DocumentController::class, 'clearValidDocument']);

});

// Publish simulator
Route::post('/sim', [SubmitController::class, 'pubrec']);

/**
 * Admin-only endpoints for running system commands
 * These require GenCC Administrator privileges
 */
Route::group(['middleware' => ['web'], 'prefix' => 'admin'], function () {
    // Operational commands
    Route::post('/run-publish', [AdminController::class, 'runPublish']);
    Route::post('/update-diseases', [AdminController::class, 'updateDiseases']);
    Route::post('/update-genes', [AdminController::class, 'updateGenes']);
    Route::post('/sync-pubmed', [AdminController::class, 'syncPubmed']);

    // Submitter management
    Route::get('/submitters', [AdminController::class, 'listSubmitters']);
    Route::get('/submitters/{id}', [AdminController::class, 'showSubmitter']);
    Route::post('/submitters', [AdminController::class, 'storeSubmitter']);
    Route::put('/submitters/{id}', [AdminController::class, 'updateSubmitter']);
    Route::delete('/submitters/{id}', [AdminController::class, 'deleteSubmitter']);

    // User management
    Route::get('/users', [AdminController::class, 'listUsers']);
    Route::get('/users/{id}', [AdminController::class, 'showUser']);
    Route::post('/users', [AdminController::class, 'storeUser']);
    Route::put('/users/{id}', [AdminController::class, 'updateUser']);
    Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
    Route::post('/users/{id}/association', [AdminController::class, 'updateUserAssociation']);

    // Submitter member management (add/remove users from submitter detail page)
    Route::post('/submitters/{id}/members', [AdminController::class, 'addUserToSubmitter']);
    Route::delete('/submitters/{id}/members/{userId}', [AdminController::class, 'removeUserFromSubmitter']);
});

// Progress polling endpoint - outside web middleware to avoid session locking
// during long-running admin operations
Route::get('/admin/progress/{operation}', [AdminController::class, 'getProgress']);

/**
 * PubMed API endpoints
 * For retrieving PubMed article information
 */
// Search PubMed articles by title or author (must come before {pmids} route)
Route::get('/pubmed/search', [PubmedController::class, 'search']);

// Get PubMed article(s) by PMID(s) - comma-separated list supported
Route::get('/pubmed/{pmids}', [PubmedController::class, 'show']);
