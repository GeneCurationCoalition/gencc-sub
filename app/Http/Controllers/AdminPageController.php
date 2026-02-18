<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Models\Release;
use App\Models\Submitter;
use App\Models\User;
use Google\Cloud\Storage\StorageClient;

class AdminPageController extends Controller
{
    /**
     * Ensure the current user is a GenCC admin.
     */
    private function checkAdmin()
    {
        $user = Auth::user();
        if (!$user || !$user->isGenccAdmin()) {
            abort(403, 'Unauthorized: Admin access required');
        }
    }

    /**
     * Display the admin submitter list page.
     */
    public function submitters(Request $request)
    {
        $this->checkAdmin();

        $submitters = Submitter::select('id', 'curie', 'name', 'status', 'allow_submissions', 'downloadable')
            ->withCount(['users', 'jobs', 'submissions'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Submitters', [
            'submitters' => $submitters,
        ]);
    }

    /**
     * Display the admin submitter detail page.
     */
    public function submitterDetail(Request $request, string $id)
    {
        $this->checkAdmin();

        $submitter = Submitter::withCount('users', 'jobs', 'submissions')->find($id);

        if (!$submitter) {
            abort(404);
        }

        // Build submitter data with logo as data URI
        $submitterData = $submitter->only(['id', 'ident', 'curie', 'name', 'description', 'website', 'assertion', 'status', 'allow_submissions', 'downloadable', 'users_count', 'jobs_count', 'submissions_count']);

        if ($submitter->logo_contents && $submitter->logo_mime_type) {
            $submitterData['logo'] = 'data:' . $submitter->logo_mime_type . ';base64,' . $submitter->logo_contents;
        } else {
            $submitterData['logo'] = null;
        }

        // Get members (users) associated with this submitter
        $members = $submitter->users()->get()->map(function ($member) {
            return [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'title' => $member->title,
                'phone' => $member->phone,
                'status' => $member->status,
                'is_contact' => $member->pivot->is_contact,
            ];
        });

        // Get all active submitters for reference (e.g., user reassignment)
        $allSubmitters = Submitter::where('status', Submitter::STATUS_ACTIVE)
            ->select('id', 'name', 'curie')
            ->orderBy('name')
            ->get();

        // Get all active users for adding members
        $allUsers = User::where('status', User::STATUS_ACTIVE)
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/SubmitterDetail', [
            'submitter' => $submitterData,
            'members' => $members,
            'allSubmitters' => $allSubmitters,
            'allUsers' => $allUsers,
            'authUserId' => Auth::id(),
        ]);
    }

    /**
     * Display the admin user list page.
     */
    public function users(Request $request)
    {
        $this->checkAdmin();

        // Get admin team member IDs in a single query
        $adminTeam = \App\Models\Team::where('name', 'admin')->where('personal_team', false)->first();
        $adminUserIds = $adminTeam ? $adminTeam->users()->pluck('users.id')->toArray() : [];

        $users = User::with(['submitters' => function ($q) {
                $q->select('submitters.id', 'submitters.name', 'submitters.curie');
            }])
            ->select('id', 'name', 'email', 'title', 'clingen_id', 'submitter_id', 'status')
            ->orderBy('name')
            ->get()
            ->map(function ($user) use ($adminUserIds) {
                $user->is_admin = in_array($user->id, $adminUserIds);
                return $user;
            });

        $allSubmitters = Submitter::where('status', Submitter::STATUS_ACTIVE)
            ->select('id', 'name', 'curie')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'allSubmitters' => $allSubmitters,
        ]);
    }

    /**
     * Display the admin user detail page.
     */
    public function userDetail(Request $request, string $id)
    {
        $this->checkAdmin();

        $user = User::with(['submitters' => function ($q) {
                $q->select('submitters.id', 'submitters.name', 'submitters.curie')
                  ->withPivot('is_contact');
            }])
            ->withCount(['jobs', 'submissions'])
            ->find($id);

        if (!$user) {
            abort(404);
        }

        $userData = $user->only(['id', 'ident', 'name', 'email', 'title', 'phone', 'clingen_id', 'submitter_id', 'status', 'must_change_password', 'created_at']);
        $userData['is_admin'] = $user->isGenccAdmin();
        $userData['jobs_count'] = $user->jobs_count;
        $userData['submissions_count'] = $user->submissions_count;

        // Get single submitter (if any)
        $submitter = $user->submitters->first();
        $userData['submitter'] = $submitter ? [
            'id' => $submitter->id,
            'name' => $submitter->name,
            'curie' => $submitter->curie,
            'is_contact' => $submitter->pivot->is_contact,
        ] : null;

        // All active submitters for assignment
        $allSubmitters = Submitter::where('status', Submitter::STATUS_ACTIVE)
            ->select('id', 'name', 'curie')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/UserDetail', [
            'user' => $userData,
            'allSubmitters' => $allSubmitters,
            'isSelf' => Auth::id() == $id,
        ]);
    }

    /**
     * Display the admin releases list page.
     */
    public function releases(Request $request)
    {
        $this->checkAdmin();

        $releases = Release::with('user:id,name')
            ->orderBy('released_at', 'desc')
            ->get()
            ->map(function ($release) {
                return [
                    'id' => $release->id,
                    'slug' => $release->slug,
                    'released_at' => $release->released_at?->toIso8601String(),
                    'user_name' => $release->user?->name ?? 'System',
                    'new_count' => $release->new_count,
                    'republish_count' => $release->republish_count,
                    'unpublish_count' => $release->unpublish_count,
                    'failed_count' => $release->failed_count,
                    'total_count' => $release->total_count,
                    'duration_seconds' => $release->duration_seconds,
                    'jobs_count' => is_array($release->jobs_processed) ? count($release->jobs_processed) : 0,
                    'errors_count' => is_array($release->errors) ? count($release->errors) : 0,
                ];
            });

        return Inertia::render('Admin/Releases', [
            'releases' => $releases,
        ]);
    }

    /**
     * Display the admin release detail page.
     */
    public function releaseDetail(Request $request, string $id)
    {
        $this->checkAdmin();

        $release = Release::with('user:id,name')->find($id);

        if (!$release) {
            abort(404);
        }

        return Inertia::render('Admin/ReleaseDetail', [
            'release' => [
                'id' => $release->id,
                'slug' => $release->slug,
                'released_at' => $release->released_at?->toIso8601String(),
                'user_name' => $release->user?->name ?? 'System',
                'release_notes_file' => $release->release_notes_file,
                'submissions_csv_file' => $release->submissions_csv_file,
                'new_count' => $release->new_count,
                'republish_count' => $release->republish_count,
                'unpublish_count' => $release->unpublish_count,
                'failed_count' => $release->failed_count,
                'total_count' => $release->total_count,
                'jobs_processed' => $release->jobs_processed ?? [],
                'errors' => $release->errors ?? [],
                'by_submitter' => $release->by_submitter ?? [],
                'cumulative_stats' => $release->cumulative_stats ?? [],
                'duration_seconds' => $release->duration_seconds,
            ],
        ]);
    }

    /**
     * Download a release CSV file.
     */
    public function downloadReleaseCsv(Request $request, string $id)
    {
        $this->checkAdmin();

        $release = Release::find($id);
        if (!$release || !$release->submissions_csv_file) {
            abort(404, 'Release or CSV file not found');
        }

        $filename = $release->submissions_csv_file;

        // Try GCS first (current path structure)
        $content = $this->downloadFromGcs("current/csv/{$filename}");
        if ($content !== null) {
            return response($content, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        // Fall back to local storage - try multiple possible paths
        $possiblePaths = [
            storage_path('app/public/current/csv/' . $filename),
            storage_path('app/public/releases/current/csv/' . $filename),
            storage_path('app/public/exports/' . $filename), // legacy path
        ];

        foreach ($possiblePaths as $filePath) {
            if (file_exists($filePath)) {
                return response()->download($filePath, $filename, [
                    'Content-Type' => 'text/csv',
                ]);
            }
        }

        abort(404, 'CSV file not found');
    }

    /**
     * Download a release notes file.
     */
    public function downloadReleaseNotes(Request $request, string $id)
    {
        $this->checkAdmin();

        $release = Release::find($id);
        if (!$release || !$release->release_notes_file) {
            abort(404, 'Release or notes file not found');
        }

        $filename = $release->release_notes_file;

        // Determine content type based on file extension (supports both .txt and legacy .md)
        $contentType = str_ends_with($filename, '.md') ? 'text/markdown' : 'text/plain';

        // Try GCS first
        $content = $this->downloadFromGcs("notes/{$filename}");
        if ($content !== null) {
            return response($content, 200, [
                'Content-Type' => $contentType,
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);
        }

        // Fall back to local storage
        $filePath = storage_path('releases/' . $filename);
        if (!file_exists($filePath)) {
            abort(404, 'Release notes file not found');
        }

        return response()->download($filePath, $filename, [
            'Content-Type' => $contentType,
        ]);
    }

    /**
     * Download a file from GCS.
     * Returns file content on success, null if GCS is not configured or file not found.
     */
    private function downloadFromGcs(string $objectName): ?string
    {
        $bucketName = config('filesystems.disks.gcs.bucket');
        if (empty($bucketName)) {
            return null;
        }

        $prefix = config('filesystems.disks.gcs.path_prefix', 'releases');
        $fullObjectName = $prefix ? "{$prefix}/{$objectName}" : $objectName;

        try {
            $storage = new StorageClient([
                'projectId' => config('filesystems.disks.gcs.project_id'),
            ]);
            $bucket = $storage->bucket($bucketName);
            $object = $bucket->object($fullObjectName);

            if (!$object->exists()) {
                return null;
            }

            return $object->downloadAsString();
        } catch (\Exception $e) {
            \Log::warning("GCS download failed for {$fullObjectName}: {$e->getMessage()}");
            return null;
        }
    }
}
