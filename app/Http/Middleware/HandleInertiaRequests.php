<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Defines the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [

            // Application version (from config/app.php)
            'appVersion' => config('app.version'),

            // Session idle timeout in milliseconds (for AutoLogout component)
            'sessionIdleTimeout' => (int) config('session.idle_timeout', 900000),

            // Lazily...
            'mine' => fn () => $request->user()
                ? $request->user()->id
                : null,

            // User's submitter data
            'userSubmitter' => fn () => $request->user() && $request->user()->submitter ? [
                'id' => $request->user()->submitter->id,
                'name' => $request->user()->submitter->name,
                'curie' => $request->user()->submitter->curie,
            ] : null,

            // Check if user is GenCC Administrator
            'isGenccAdmin' => fn () => $request->user() && $request->user()->isGenccAdmin(),

            // Check if user must change their password
            'mustChangePassword' => fn () => $request->user()
                ? (bool) $request->user()->must_change_password
                : false,

            // Provide all active submitters for admin users to select from
            'submitters' => fn () => $request->user() && $request->user()->isGenccAdmin()
                ? \App\Models\Submitter::where('status', \App\Models\Submitter::STATUS_ACTIVE)
                    ->select('id', 'name', 'curie')
                    ->orderBy('name')
                    ->get()
                : null,

            // Selected submitter for admin session
            'selectedSubmitter' => fn () => $request->user() &&
                $request->user()->isGenccAdmin() &&
                $request->session()->has('selected_submitter_id')
                ? \App\Models\Submitter::where('id', $request->session()->get('selected_submitter_id'))
                    ->select('id', 'name', 'curie')
                    ->first()
                : null,

            // Check if admin user needs to select a submitter to access Jobs/Submissions
            'adminNeedsSubmitterSelection' => fn () => $request->user() &&
                $request->user()->isGenccAdmin() &&
                !$request->session()->has('selected_submitter_id'),

        ]);
    }
}
