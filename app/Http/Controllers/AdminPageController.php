<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use App\Models\Submitter;
use App\Models\User;

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

        $submitters = Submitter::withCount('users')
            ->select('id', 'curie', 'name', 'website', 'status')
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
        $submitterData = $submitter->only(['id', 'ident', 'curie', 'name', 'description', 'website', 'assertion', 'status', 'users_count', 'jobs_count', 'submissions_count']);

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
}
