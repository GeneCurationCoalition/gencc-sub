<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Jobs\RunAdminCommand;
use App\Mail\NewUserWelcome;
use App\Mail\PasswordResetByAdmin;
use App\Models\AdminLog;
use App\Models\Submitter;
use App\Models\User;
use App\Services\AdminProgressTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AdminController extends Controller
{
    /**
     * Check if the current user is a GenCC admin.
     */
    private function checkAdmin()
    {
        $user = Auth::user();
        if (!$user || !$user->isGenccAdmin()) {
            abort(403, 'Unauthorized: Admin access required');
        }
    }

    /**
     * Dispatch an artisan command to run in the background via the queue.
     *
     * @param string $operation The operation constant from AdminLog
     * @param string $command The artisan command to run
     * @param string $summaryType Summary generator type (publish, diseases, genes, pubmed, default)
     * @return \Illuminate\Http\JsonResponse
     */
    private function runAndLog(string $operation, string $command, string $summaryType = 'default')
    {
        $this->checkAdmin();
        $user = Auth::user();

        // Prevent duplicate runs
        if (AdminProgressTracker::isRunning($operation)) {
            return response()->json([
                'success' => false,
                'started' => false,
                'message' => 'This operation is already running',
            ], 409);
        }

        Log::info("Admin action: Dispatching {$command}", ['user' => $user->email]);

        // Initialize progress tracking so frontend can poll immediately
        AdminProgressTracker::start($operation);

        // Dispatch to queue for background execution
        RunAdminCommand::dispatch($operation, $command, $user->id, $summaryType);

        return response()->json([
            'success' => true,
            'started' => true,
            'message' => 'Operation started',
        ]);
    }

    /**
     * Run the gencc:release command to publish pending submissions.
     */
    public function runPublish(Request $request)
    {
        return $this->runAndLog(AdminLog::OP_RUN_PUBLISH, 'gencc:release', 'publish');
    }

    /**
     * Run the update:diseases command.
     */
    public function updateDiseases(Request $request)
    {
        return $this->runAndLog(AdminLog::OP_UPDATE_DISEASES, 'update:diseases', 'diseases');
    }

    /**
     * Run the update:genes command.
     */
    public function updateGenes(Request $request)
    {
        return $this->runAndLog(AdminLog::OP_UPDATE_GENES, 'update:genes', 'genes');
    }

    /**
     * Run the pubmed:sync command to fetch pending PubMed data.
     */
    public function syncPubmed(Request $request)
    {
        return $this->runAndLog(AdminLog::OP_SYNC_PUBMED, 'pubmed:sync', 'pubmed');
    }

    /**
     * Run the gencc:release repair command to fix a failed release.
     */
    public function repairRelease(Request $request)
    {
        $this->checkAdmin();
        $user = Auth::user();

        // Clear any stale progress tracker state first
        AdminProgressTracker::clear('run_publish');

        Log::info("Admin action: Dispatching gencc:release repair", ['user' => $user->email]);

        // Initialize progress tracking
        AdminProgressTracker::start('run_publish');

        // Dispatch repair command with --no-interaction flag
        RunAdminCommand::dispatch(
            AdminLog::OP_RUN_PUBLISH,
            'gencc:release repair --user_id=' . $user->id . ' --no-interaction',
            $user->id,
            'publish'
        );

        return response()->json([
            'success' => true,
            'started' => true,
            'message' => 'Release repair started',
        ]);
    }

    /**
     * Get progress for a running admin operation.
     *
     * Note: This endpoint is outside the web middleware to avoid session locking
     * during long-running admin operations. Progress data is not sensitive
     * (just status messages), so we skip auth checks here.
     */
    public function getProgress(Request $request, string $operation)
    {
        // Note: Auth check removed to avoid session locking issues
        // The actual admin operations still require authentication

        $progress = AdminProgressTracker::get($operation);

        if (!$progress) {
            return response()->json([
                'status' => 'not_found',
                'message' => 'No progress data found for this operation'
            ]);
        }

        return response()->json($progress);
    }

    /**
     * Clear a stale admin operation.
     * Operations are considered stale if they've been "running" for 15+ minutes without updates.
     */
    public function clearStaleOperation(Request $request, string $operation)
    {
        $this->checkAdmin();

        $progress = AdminProgressTracker::get($operation);

        if (!$progress) {
            return response()->json([
                'success' => false,
                'message' => 'Operation not found',
            ], 404);
        }

        // Only allow clearing stale or completed operations
        if ($progress['status'] === 'running' && empty($progress['is_stale'])) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot clear an actively running operation. Wait for it to complete or become stale.',
            ], 409);
        }

        AdminProgressTracker::clear($operation);

        Log::info("Admin action: Cleared stale operation {$operation}", ['user' => Auth::user()->email]);

        return response()->json([
            'success' => true,
            'message' => 'Operation cleared successfully',
        ]);
    }

    // =========================================================================
    // Submitter Management
    // =========================================================================

    /**
     * List all submitters with optional search.
     */
    public function listSubmitters(Request $request)
    {
        $this->checkAdmin();

        $query = Submitter::withCount('users');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('curie', 'like', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $submitters = $query->orderBy('name')->get();

        return response()->json($submitters);
    }

    /**
     * Get a single submitter with its users.
     */
    public function showSubmitter(Request $request, string $id)
    {
        $this->checkAdmin();

        $submitter = Submitter::with(['users' => function ($q) {
            $q->select('users.id', 'users.name', 'users.email', 'users.title', 'users.phone', 'users.status')
              ->withPivot('is_contact');
        }])->withCount('users', 'jobs', 'submissions')->find($id);

        if (!$submitter) {
            return response()->json(['message' => 'Submitter not found'], 404);
        }

        // Build logo data URI
        $submitterData = $submitter->toArray();
        if ($submitter->logo_contents && $submitter->logo_mime_type) {
            $submitterData['logo'] = 'data:' . $submitter->logo_mime_type . ';base64,' . $submitter->logo_contents;
        } else {
            $submitterData['logo'] = null;
        }
        unset($submitterData['logo_contents'], $submitterData['logo_mime_type']);

        return response()->json($submitterData);
    }

    /**
     * Create a new submitter.
     */
    public function storeSubmitter(Request $request)
    {
        $this->checkAdmin();

        $validated = $request->validate([
            'name' => 'required|string|max:248',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url|max:500',
            'assertion' => 'nullable|string|max:1000',
            'downloadable' => 'nullable|boolean',
        ]);

        // Convert downloadable to boolean if present
        if ($request->has('downloadable')) {
            $validated['downloadable'] = filter_var($request->downloadable, FILTER_VALIDATE_BOOLEAN);
        }

        $submitter = Submitter::createSubmitter($validated);

        // Reload to include auto-assigned curie
        $submitter->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Submitter created',
            'submitter' => $submitter,
        ], 201);
    }

    /**
     * Update an existing submitter.
     */
    public function updateSubmitter(Request $request, string $id)
    {
        $this->checkAdmin();

        $submitter = Submitter::find($id);
        if (!$submitter) {
            return response()->json(['message' => 'Submitter not found'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:248',
            'description' => 'nullable|string|max:1000',
            'website' => 'nullable|url|max:500',
            'assertion' => 'nullable|string|max:1000',
            'status' => 'nullable|integer',
            'logo' => 'nullable|file|max:500',
            'remove_logo' => 'nullable',
            'contact_id' => 'nullable|integer|exists:users,id',
            'allow_submissions' => 'nullable',
            'downloadable' => 'nullable',
        ]);

        $submitter->name = $validated['name'];
        $submitter->description = $validated['description'] ?? null;
        $submitter->website = $validated['website'] ?? null;
        $submitter->assertion = $validated['assertion'] ?? null;

        if (isset($validated['status'])) {
            $submitter->status = $validated['status'];
        }

        // Handle allow_submissions and downloadable flags
        if ($request->has('allow_submissions')) {
            $submitter->allow_submissions = filter_var($request->allow_submissions, FILTER_VALIDATE_BOOLEAN);
        }
        if ($request->has('downloadable')) {
            $submitter->downloadable = filter_var($request->downloadable, FILTER_VALIDATE_BOOLEAN);
        }

        // Handle logo removal
        if ($request->has('remove_logo') && $request->remove_logo) {
            $submitter->logo = null;
            $submitter->logo_contents = null;
            $submitter->logo_mime_type = null;
        }
        // Handle logo upload
        elseif ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $mimeType = $file->getMimeType();

            if ($mimeType !== 'image/png') {
                return response()->json(['message' => 'Logo must be a PNG image'], 422);
            }

            $contents = file_get_contents($file->getRealPath());
            $submitter->logo_contents = base64_encode($contents);
            $submitter->logo_mime_type = $mimeType;
            $submitter->logo = null;
        }

        // Handle contact update
        if ($request->has('contact_id')) {
            DB::table('submitter_user')
                ->where('submitter_id', $submitter->id)
                ->update(['is_contact' => false]);

            if ($request->contact_id) {
                DB::table('submitter_user')
                    ->where('submitter_id', $submitter->id)
                    ->where('user_id', $request->contact_id)
                    ->update(['is_contact' => true]);
            }
        }

        $submitter->save();

        return response()->json([
            'success' => true,
            'message' => 'Submitter updated',
        ]);
    }

    /**
     * Delete a submitter. Only permanently deletes if the submitter has no jobs,
     * submissions, or user members. Otherwise deactivates (status = Removed).
     */
    public function deleteSubmitter(Request $request, string $id)
    {
        $this->checkAdmin();

        $submitter = Submitter::withCount(['jobs', 'submissions', 'users'])->find($id);
        if (!$submitter) {
            return response()->json(['message' => 'Submitter not found'], 404);
        }

        if ($submitter->jobs_count === 0 && $submitter->submissions_count === 0 && $submitter->users_count === 0) {
            // No associations — safe to permanently delete
            $submitter->users()->detach();
            $submitter->forceDelete();

            return response()->json([
                'success' => true,
                'message' => 'Submitter permanently deleted.',
                'deleted' => true,
            ]);
        }

        // Has associations — deactivate instead
        $submitter->status = Submitter::STATUS_REMOVED;
        $submitter->save();

        return response()->json([
            'success' => true,
            'message' => 'Submitter has been deactivated.',
            'deleted' => false,
        ]);
    }

    // =========================================================================
    // User Management
    // =========================================================================

    /**
     * List all users with optional search/filter.
     */
    public function listUsers(Request $request)
    {
        $this->checkAdmin();

        $query = User::with(['submitters' => function ($q) {
            $q->select('submitters.id', 'submitters.name', 'submitters.curie');
        }]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('clingen_id', 'like', "%{$search}%");
            });
        }

        if ($submitterId = $request->query('submitter_id')) {
            $query->whereHas('submitters', function ($q) use ($submitterId) {
                $q->where('submitters.id', $submitterId);
            });
        }

        $users = $query->select('id', 'name', 'email', 'title', 'phone', 'clingen_id', 'submitter_id', 'status')
            ->orderBy('name')
            ->get();

        return response()->json($users);
    }

    /**
     * Get a single user with submitter associations.
     */
    public function showUser(Request $request, string $id)
    {
        $this->checkAdmin();

        $user = User::with(['submitters' => function ($q) {
            $q->select('submitters.id', 'submitters.name', 'submitters.curie')
              ->withPivot('is_contact');
        }])->select('id', 'ident', 'name', 'email', 'title', 'phone', 'clingen_id', 'submitter_id', 'status', 'created_at')
          ->find($id);

        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $userData = $user->toArray();
        $userData['is_admin'] = $user->isGenccAdmin();

        return response()->json($userData);
    }

    /**
     * Create a new user.
     * User is assigned to either a submitter OR the admin team (mutually exclusive).
     * A temporary password is auto-generated and emailed to the user.
     */
    public function storeUser(Request $request)
    {
        $this->checkAdmin();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email|max:255',
            'submitter_id' => 'nullable|integer|exists:submitters,id',
            'is_admin' => 'nullable|boolean',
            'title' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
        ]);

        $isAdmin = $validated['is_admin'] ?? false;

        if (!$isAdmin && empty($validated['submitter_id'])) {
            return response()->json(['message' => 'Either a submitter or admin team assignment is required.'], 422);
        }

        // Generate temporary password
        $tempPassword = Str::password(12);
        $validated['password'] = $tempPassword;

        try {
            $user = User::createUser($validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Set must_change_password flag
        $user->must_change_password = true;
        $user->save();

        if ($isAdmin) {
            // Add to admin team, clear submitter
            $user->submitter_id = null;
            $user->save();
            $user->submitters()->detach();
            $this->addToAdminTeam($user);
        } else {
            // Link user to single submitter via pivot
            $user->submitters()->sync([$validated['submitter_id']]);
            $this->removeFromAdminTeam($user);
        }

        // Send welcome email with temporary password
        Mail::to($user->email)->send(new NewUserWelcome($user, $tempPassword));

        return response()->json([
            'success' => true,
            'message' => 'User created. A welcome email with login credentials has been sent.',
            'user' => $user->only(['id', 'name', 'email', 'clingen_id']),
        ], 201);
    }

    /**
     * Update an existing user.
     */
    public function updateUser(Request $request, string $id)
    {
        $this->checkAdmin();

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Prevent admins from changing their own status
        $isSelf = Auth::id() == $id;
        if ($isSelf && $request->has('status') && $request->input('status') != $user->status) {
            return response()->json(['message' => 'You cannot change your own status.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,' . $user->id,
            'title' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'status' => 'nullable|integer',
            'submitter_id' => 'nullable|integer|exists:submitters,id',
            'must_change_password' => 'nullable|boolean',
        ]);

        if (isset($validated['name'])) {
            $user->add_name($validated['name']);
        }
        if (isset($validated['email'])) {
            $user->email = $validated['email'];
        }
        if (array_key_exists('title', $validated)) {
            $user->title = $validated['title'];
        }
        if (array_key_exists('phone', $validated)) {
            $user->phone = $validated['phone'];
        }
        if (isset($validated['status'])) {
            $user->status = $validated['status'];
        }
        if (isset($validated['submitter_id'])) {
            $user->submitter_id = $validated['submitter_id'];
        }
        if (array_key_exists('must_change_password', $validated)) {
            // When enabling the flag, generate a temporary password and email it
            if ($validated['must_change_password'] && !$user->must_change_password) {
                $tempPassword = Str::password(12);
                $user->password = bcrypt($tempPassword);
                $user->must_change_password = true;

                Mail::to($user->email)->send(new PasswordResetByAdmin($user, $tempPassword));
            } else {
                $user->must_change_password = $validated['must_change_password'];
            }
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User updated',
        ]);
    }

    /**
     * Delete a user. Only permanently deletes if the user has no jobs, submissions,
     * submitter associations, or admin team membership. Otherwise deactivates.
     */
    public function deleteUser(Request $request, string $id)
    {
        $this->checkAdmin();

        // Prevent admins from deleting themselves
        if (Auth::id() == $id) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 403);
        }

        $user = User::withCount(['jobs', 'submissions'])->find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $hasSubmitters = $user->submitters()->count() > 0;
        $isAdmin = $user->isGenccAdmin();

        if ($user->jobs_count === 0 && $user->submissions_count === 0 && !$hasSubmitters && !$isAdmin) {
            // No associations — safe to permanently delete
            $user->submitters()->detach();
            $user->teams()->detach();
            $user->forceDelete();

            return response()->json([
                'success' => true,
                'message' => 'User permanently deleted.',
                'deleted' => true,
            ]);
        }

        // Has associations — deactivate instead
        $user->status = User::STATUS_REMOVED;
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User has been deactivated.',
            'deleted' => false,
        ]);
    }

    /**
     * Update user association: assign to a single submitter OR admin team.
     * Mutually exclusive — assigning to a submitter removes from admin team, and vice versa.
     */
    public function updateUserAssociation(Request $request, string $id)
    {
        $this->checkAdmin();

        // Prevent admins from changing their own type
        if (Auth::id() == $id) {
            return response()->json(['message' => 'You cannot change your own type.'], 403);
        }

        $user = User::find($id);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $validated = $request->validate([
            'submitter_id' => 'nullable|integer|exists:submitters,id',
            'is_admin' => 'nullable|boolean',
            'is_contact' => 'nullable|boolean',
        ]);

        $isAdmin = $validated['is_admin'] ?? false;

        if (!$isAdmin && empty($validated['submitter_id'])) {
            return response()->json(['message' => 'Either a submitter or admin team assignment is required.'], 422);
        }

        if ($isAdmin) {
            // Assign to admin team, remove from submitter
            $user->submitter_id = null;
            $user->save();
            $user->submitters()->detach();
            $this->addToAdminTeam($user);
        } else {
            // Assign to single submitter, remove from admin team
            $submitterId = $validated['submitter_id'];
            $isContact = $validated['is_contact'] ?? false;

            $user->submitter_id = $submitterId;
            $user->save();
            $user->submitters()->sync([
                $submitterId => ['is_contact' => $isContact],
            ]);
            $this->removeFromAdminTeam($user);
        }

        return response()->json([
            'success' => true,
            'message' => 'User association updated',
        ]);
    }

    /**
     * Add a user to a submitter (used from SubmitterDetail).
     * Automatically removes from current submitter or admin team.
     */
    public function addUserToSubmitter(Request $request, string $submitterId)
    {
        $this->checkAdmin();

        $submitter = Submitter::find($submitterId);
        if (!$submitter) {
            return response()->json(['message' => 'Submitter not found'], 404);
        }

        $validated = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
        ]);

        // Prevent admin from adding themselves to a submitter (would remove from admin team)
        if (Auth::id() == $validated['user_id']) {
            return response()->json(['message' => 'You cannot add yourself to a submitter.'], 403);
        }

        $user = User::find($validated['user_id']);

        // Switch user to this submitter
        $user->submitter_id = $submitter->id;
        $user->save();
        $user->submitters()->sync([$submitter->id]);
        $this->removeFromAdminTeam($user);

        return response()->json([
            'success' => true,
            'message' => "{$user->name} has been added to {$submitter->name}",
        ]);
    }

    /**
     * Remove a user from a submitter (used from SubmitterDetail).
     */
    public function removeUserFromSubmitter(Request $request, string $submitterId, string $userId)
    {
        $this->checkAdmin();

        $submitter = Submitter::find($submitterId);
        if (!$submitter) {
            return response()->json(['message' => 'Submitter not found'], 404);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Only detach from this submitter if they belong to it
        if ($user->submitter_id == $submitter->id) {
            $user->submitter_id = null;
            $user->save();
        }
        $user->submitters()->detach($submitter->id);

        return response()->json([
            'success' => true,
            'message' => "{$user->name} has been removed from {$submitter->name}",
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Add user to the admin team.
     */
    private function addToAdminTeam(User $user): void
    {
        $adminTeam = \App\Models\Team::where('name', 'admin')
            ->where('personal_team', false)
            ->first();

        if ($adminTeam && !$adminTeam->hasUser($user)) {
            $adminTeam->users()->attach($user->id, ['role' => 'editor']);
        }
    }

    /**
     * Remove user from the admin team (if they're on it).
     */
    private function removeFromAdminTeam(User $user): void
    {
        $adminTeam = \App\Models\Team::where('name', 'admin')
            ->where('personal_team', false)
            ->first();

        if ($adminTeam) {
            // Don't remove the team owner
            if ($user->id !== $adminTeam->user_id) {
                $adminTeam->users()->detach($user->id);
            }
        }
    }
}
