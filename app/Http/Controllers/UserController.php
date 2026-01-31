<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

use Auth;

use App\Models\User;
use App\Models\Team;

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
 * UserController supplies the user data to Inertia/Vue.
 *
 * */
class UserController extends Controller
{
    /**
     * Pass user and submitter information to the profile page
     */
    public function show()
    {
        $user = Auth::user();

        // Get submitters with role info from pivot
        $submitters = $user->submitters()->get()->map(function ($submitter) {
            return [
                'id' => $submitter->id,
                'curie' => $submitter->curie,
                'name' => $submitter->name,
                'is_contact' => $submitter->pivot->is_contact,
            ];
        });

        // Check if user is a member of the "admin" team
        $adminTeam = Team::where('name', 'admin')
            ->where('personal_team', false)
            ->first();

        $adminTeamData = null;
        if ($adminTeam && $adminTeam->hasUser($user)) {
            $adminTeamData = [
                'id' => $adminTeam->id,
                'name' => $adminTeam->name,
            ];
        }

        return Inertia::render('Profile', [
                'user' => $user->only(['id', 'ident', 'name', 'email', 'title', 'phone', 'preferences', 'clingen_id', 'api_token', 'api_token_renewed_at']),
                'submitters' => $submitters,
                'adminTeam' => $adminTeamData,
        ]);
    }

    /**
     * Pass submitter information to the submitter settings page
     */
    public function showSubmitter()
    {
        $user = Auth::user();
        $submitter = $user->submitter;

        // Build submitter data with logo as data URI
        $submitterData = $submitter->only(['id', 'ident', 'curie', 'name', 'description', 'website', 'assertion']);

        // Build logo data URI from binary contents stored in database
        if ($submitter->logo_contents && $submitter->logo_mime_type) {
            $submitterData['logo'] = 'data:' . $submitter->logo_mime_type . ';base64,' . $submitter->logo_contents;
        } else {
            $submitterData['logo'] = null;
        }

        // Get members (users) associated with this submitter (via pivot)
        $members = $submitter->users()->get()->map(function ($member) {
            return [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'is_contact' => $member->pivot->is_contact,
            ];
        });

        return Inertia::render('SubmitterSettings', [
            'submitter' => $submitterData,
            'members' => $members,
            'canEdit' => true
        ]);
    }

    /**
     * Pass team information to the team settings page
     */
    public function showTeam()
    {
        $user = Auth::user();

        // Only admin users can view the admin team
        if (!$user || !$user->isGenccAdmin()) {
            abort(403, 'Unauthorized: Admin access required');
        }

        // Load the "admin" non-personal team
        $team = Team::where('name', 'admin')
            ->where('personal_team', false)
            ->first();

        if (!$team) {
            abort(404, 'Admin team not found');
        }

        // Get team members
        $members = $team->allUsers()->map(function ($member) {
            return [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
            ];
        });

        // Check if user can edit (is team owner)
        $canEdit = $user->id === $team->user_id;

        // Get all active users for member selection (only if canEdit)
        $allUsers = $canEdit
            ? User::where('status', User::STATUS_ACTIVE)
                ->select('id', 'name', 'email')
                ->orderBy('name')
                ->get()
            : [];

        return Inertia::render('TeamSettings', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'owner' => $team->owner ? ['id' => $team->owner->id, 'name' => $team->owner->name] : null,
            ],
            'members' => $members,
            'canEdit' => $canEdit,
            'allUsers' => $allUsers,
            'isAdminTeam' => true,
            'authUserId' => $user->id,
        ]);
    }

    /**
     * Set selected submitter for GenCC Administrator users
     */
    public function setSelectedSubmitter(Request $request)
    {
        $user = Auth::user();

        // Verify user is GenCC Administrator
        if (!$user || !$user->isGenccAdmin()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'submitter_id' => 'nullable|integer|exists:submitters,id',
            'redirect_to' => 'nullable|string',
            'clear' => 'nullable|string'
        ]);

        // Store selected submitter in session or clear it
        if ($request->has('clear') && $request->clear) {
            // Clear selection
            $request->session()->forget('selected_submitter_id');
        } elseif ($request->submitter_id) {
            // Set selection
            $request->session()->put('selected_submitter_id', $request->submitter_id);
        } else {
            // Clear if no submitter_id provided
            $request->session()->forget('selected_submitter_id');
        }

        // If redirect_to is provided, use it; otherwise go back
        if ($request->redirect_to) {
            return redirect($request->redirect_to);
        }

        return back();
    }
}
