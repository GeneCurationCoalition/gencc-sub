<?php

use App\Http\Controllers\UserInformationController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AliasController;
use App\Http\Controllers\SubmissionDirectionsController;


/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
    
    Route::get('/', function () {
        return Inertia::render('Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'laravelVersion' => Application::VERSION,
            'phpVersion' => PHP_VERSION,
        ]);
    });

    // Download GenCC submission template (public - no auth required)
    Route::get('/download/template', function () {
        $templatePath = public_path('documents/GenCC Submission Spreadsheet.xlsx');

        // If local template exists, serve it
        if (file_exists($templatePath)) {
            return response()->download($templatePath, 'GenCC Submission Spreadsheet.xlsx');
        }

        // Fallback to external URL
        return redirect('https://search.thegencc.org/download/gene-curations-template');
    })->name('download.template');

    Route::middleware([
        'auth:sanctum',
        config('jetstream.auth_session'),
        'verified',
    ])->group(function () {

    Route::get('/help', function () {
        return Inertia::render('Help');
    })->name('help');

    Route::get('/submission-directions', [SubmissionDirectionsController::class, 'index'])->name('submission-directions');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('/api/clingen/sync', [DashboardController::class, 'clingenSync'])->name('clingen.sync');

    Route::get('/jobs', [JobController::class, 'index'])->name('jobs.index');

    Route::get('/jobs/{id}', [JobController::class, 'show'])->name('jobs.show');

    Route::get('/submissions', [SubmissionController::class, 'index'])->name('submissions.index');

    Route::get('/submissions/{id}', [SubmissionController::class, 'show'])->name('submissions.show');

    Route::get('/profile', [UserController::class, 'show'])->name('profile.show');

    Route::match(['get', 'post'], '/user/select-submitter', [UserController::class, 'setSelectedSubmitter'])->name('user.select-submitter');

    Route::get('/test', [ClientController::class, 'test_all'])->name('test.all');

    Route::get('/aliases', [AliasController::class, 'index'])->name('alias.index');

   // Route::get('/userinfo',  [UserInformationController::class, 'create']);

    //Route::post('/userinfo-store',  [UserInformationController::class, 'store'])->name('user-informations.store');

});
   