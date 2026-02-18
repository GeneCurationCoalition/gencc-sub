<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

use App\Http\Controllers\SubmissionController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AliasController;
use App\Http\Controllers\AdminPageController;
use App\Http\Controllers\HelpController;


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

// Load balancer / container health check (public - no auth required)
Route::get('/-/healthz', function () {
    return response('ok', 200);
});

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

        // If local template exists, serve it with proper cache validation
        if (file_exists($templatePath)) {
            $response = response()->download($templatePath, 'GenCC Submission Spreadsheet.xlsx');
            // Use ETag based on file content hash + modification time
            $etag = '"' . md5_file($templatePath) . '-' . filemtime($templatePath) . '"';
            $response->setEtag($etag);
            $response->setLastModified(\DateTime::createFromFormat('U', filemtime($templatePath)));
            // Always revalidate with server before using cached copy
            $response->headers->set('Cache-Control', 'private, no-cache');
            return $response;
        }

        // Fallback to external URL
        return redirect('https://search.thegencc.org/download/gene-curations-template');
    })->name('download.template');

    // Download User Guide PDF (with proper cache validation)
    Route::get('/download/user-guide', function () {
        $path = public_path('documents/UserGuide.pdf');
        if (file_exists($path)) {
            $response = response()->download($path, 'GenCC User Guide.pdf');
            $etag = '"' . md5_file($path) . '-' . filemtime($path) . '"';
            $response->setEtag($etag);
            $response->setLastModified(\DateTime::createFromFormat('U', filemtime($path)));
            $response->headers->set('Cache-Control', 'private, no-cache');
            return $response;
        }
        abort(404);
    })->name('download.user-guide');

    // Download API Guide PDF (with proper cache validation)
    Route::get('/download/api-guide', function () {
        $path = public_path('documents/APIGuide.pdf');
        if (file_exists($path)) {
            $response = response()->download($path, 'GenCC API Guide.pdf');
            $etag = '"' . md5_file($path) . '-' . filemtime($path) . '"';
            $response->setEtag($etag);
            $response->setLastModified(\DateTime::createFromFormat('U', filemtime($path)));
            $response->headers->set('Cache-Control', 'private, no-cache');
            return $response;
        }
        abort(404);
    })->name('download.api-guide');

    Route::middleware([
        'auth:sanctum',
        config('jetstream.auth_session'),
        'verified',
        'password.changed',
    ])->group(function () {

    Route::get('/help', [HelpController::class, 'index'])->name('help');

    Route::redirect('/submission-directions', '/help')->name('submission-directions');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('/clingen/sync', [DashboardController::class, 'clingenSync'])->name('clingen.sync');

    Route::get('/jobs', [JobController::class, 'index'])->name('jobs.index');

    Route::get('/jobs/{id}', [JobController::class, 'show'])->name('jobs.show');

    Route::get('/submissions', [SubmissionController::class, 'index'])->name('submissions.index');

    Route::get('/submissions/{id}', [SubmissionController::class, 'show'])->name('submissions.show');

    Route::get('/profile', [UserController::class, 'show'])->name('profile.show');

    Route::get('/submitter', [UserController::class, 'showSubmitter'])->name('submitter.show');

    Route::get('/team', [UserController::class, 'showTeam'])->name('team.show');

    Route::match(['get', 'post'], '/user/select-submitter', [UserController::class, 'setSelectedSubmitter'])->name('user.select-submitter');

    Route::get('/test', [ClientController::class, 'test_all'])->name('test.all');

    Route::get('/aliases', [AliasController::class, 'index'])->name('alias.index');

    // Admin pages (protected by isGenccAdmin() check in controller)
    Route::get('/admin/submitters', [AdminPageController::class, 'submitters'])->name('admin.submitters');
    Route::get('/admin/submitters/{id}', [AdminPageController::class, 'submitterDetail'])->name('admin.submitters.show');
    Route::get('/admin/users', [AdminPageController::class, 'users'])->name('admin.users');
    Route::get('/admin/users/{id}', [AdminPageController::class, 'userDetail'])->name('admin.users.show');
    Route::get('/admin/releases', [AdminPageController::class, 'releases'])->name('admin.releases');
    Route::get('/admin/releases/{id}', [AdminPageController::class, 'releaseDetail'])->name('admin.releases.show');
    Route::get('/admin/releases/{id}/download-csv', [AdminPageController::class, 'downloadReleaseCsv'])->name('admin.releases.download-csv');
    Route::get('/admin/releases/{id}/download-notes', [AdminPageController::class, 'downloadReleaseNotes'])->name('admin.releases.download-notes');
});
