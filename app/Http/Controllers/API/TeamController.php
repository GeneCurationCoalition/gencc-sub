<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Auth;
use App\Models\Team;
use App\Models\User;

class TeamController extends Controller
{
    /**
     * Update the specified team.
     */
    public function update(Request $request, string $id)
    {
        $user = Auth::user();
        $team = Team::find($id);

        if ($team === null) {
            return response()->json(['success' => 'false',
                'status_code' => 3001,
                'message' => 'Team not found'],
                200);
        }

        // Check if user is the team owner
        if ($user === null || $user->id != $team->user_id) {
            return response()->json(['success' => 'false',
                'status_code' => 3002,
                'message' => 'Unauthorized - only team owner can update'],
                200);
        }

        // The admin team name can never be changed
        if (!$team->personal_team && $team->name === 'admin') {
            return response()->json(['success' => 'false',
                'status_code' => 3003,
                'message' => 'The admin team name cannot be changed.'],
                200);
        }

        // Validate and update team name
        $request->validate([
            'name' => 'required|string|max:248',
        ]);

        $team->name = $request->input('name');
        $team->save();

        return response()->json(['success' => 'true',
            'status_code' => 200,
            'message' => 'Team Updated'],
            200);
    }

    /**
     * Add a member to the team.
     * Only the team owner can add members.
     */
    public function addMember(Request $request, string $id)
    {
        $user = Auth::user();
        $team = Team::find($id);

        if ($team === null) {
            return response()->json(['message' => 'Team not found'], 404);
        }

        if ($user === null || $user->id != $team->user_id) {
            return response()->json(['message' => 'Unauthorized - only team owner can manage members'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $memberId = $validated['user_id'];

        // Check if already a member
        if ($team->hasUser(User::find($memberId))) {
            return response()->json(['message' => 'User is already a team member'], 422);
        }

        $member = User::find($memberId);
        $team->users()->attach($memberId, ['role' => 'editor']);

        // If this is the admin team, remove the user's submitter association
        // (admin and submitter types are mutually exclusive)
        if (!$team->personal_team && $team->name === 'admin') {
            $member->submitter_id = null;
            $member->save();
            $member->submitters()->detach();
        }

        return response()->json([
            'success' => true,
            'message' => 'Member added to team',
        ]);
    }

    /**
     * Remove a member from the team.
     * Only the team owner can remove members. The owner cannot be removed.
     */
    public function removeMember(Request $request, string $id, string $userId)
    {
        $user = Auth::user();
        $team = Team::find($id);

        if ($team === null) {
            return response()->json(['message' => 'Team not found'], 404);
        }

        if ($user === null || $user->id != $team->user_id) {
            return response()->json(['message' => 'Unauthorized - only team owner can manage members'], 403);
        }

        // Cannot remove the team owner
        if ((int) $userId === $team->user_id) {
            return response()->json(['message' => 'Cannot remove the team owner'], 422);
        }

        // Cannot remove yourself from the admin team
        if (!$team->personal_team && $team->name === 'admin' && (int) $userId === $user->id) {
            return response()->json(['message' => 'You cannot remove yourself from the admin team.'], 403);
        }

        $team->users()->detach($userId);

        return response()->json([
            'success' => true,
            'message' => 'Member removed from team',
        ]);
    }
}
