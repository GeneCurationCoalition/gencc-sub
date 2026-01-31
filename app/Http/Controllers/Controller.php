<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Submitter;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /**
     * Get the effective submitter ID for the current request.
     *
     * When an admin user has selected a submitter to act as (stored in session),
     * this returns that submitter's ID. Otherwise, returns the authenticated
     * user's own submitter_id.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return int|null
     */
    protected function getEffectiveSubmitterId(Request $request): ?int
    {
        $user = Auth::user();

        if ($user?->isGenccAdmin() && $request->session()->has('selected_submitter_id')) {
            return $request->session()->get('selected_submitter_id');
        }

        return $user?->submitter_id;
    }

    /**
     * Get the effective Submitter model instance for the current request.
     *
     * Returns the Submitter that should be used for the current operation,
     * taking into account admin users acting as another submitter.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \App\Models\Submitter|null
     */
    protected function getEffectiveSubmitter(Request $request): ?Submitter
    {
        $effectiveSubmitterId = $this->getEffectiveSubmitterId($request);
        return $effectiveSubmitterId ? Submitter::find($effectiveSubmitterId) : null;
    }

    /**
     * Get a relationship query for the effective submitter.
     *
     * Convenience method that returns a query builder for a given relationship
     * (e.g., 'submissions', 'jobs', 'aliases') on the effective submitter.
     * Falls back to the authenticated user's submitter if no effective submitter found.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $relation  The relationship name (e.g., 'aliases', 'jobs', 'submissions')
     * @return mixed
     */
    protected function getEffectiveSubmitterQuery(Request $request, string $relation)
    {
        $submitter = $this->getEffectiveSubmitter($request);

        if (!$submitter) {
            $submitter = Auth::user()?->submitter;
        }

        if (!$submitter) {
            abort(403, 'No submitter associated with this user');
        }

        return $submitter->$relation();
    }

    /**
     * Check if admin user needs to select a submitter before accessing certain pages.
     *
     * Returns true if the user is an admin and has not selected a submitter to act as.
     * Used to redirect admins to the dashboard when they try to access Jobs/Submissions
     * without first selecting a submitter.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    protected function adminNeedsSubmitterSelection(Request $request): bool
    {
        $user = Auth::user();

        return $user?->isGenccAdmin() && !$request->session()->has('selected_submitter_id');
    }

    /**
     * Redirect admin users who haven't selected a submitter to the dashboard.
     *
     * Call this at the start of controller methods that require a submitter context.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse|null
     */
    protected function redirectAdminWithoutSubmitter(Request $request)
    {
        if ($this->adminNeedsSubmitterSelection($request)) {
            return redirect()->route('dashboard')->with('warning', 'Please select a submitter to view Jobs or Submissions.');
        }

        return null;
    }
}
