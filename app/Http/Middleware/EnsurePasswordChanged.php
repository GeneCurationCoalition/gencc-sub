<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    /**
     * Redirect users who must change their password to the profile page.
     * Allow access to the profile page, the password change API endpoint,
     * and logout so the user can actually change their password.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            // Allow the profile page, password change API, and logout
            $allowed = [
                'profile.show',
                'logout',
            ];

            // Allow API password change endpoint
            if ($request->is('api/users/*') && $request->isMethod('post')) {
                return $next($request);
            }

            if (!$request->routeIs($allowed)) {
                return redirect()->route('profile.show');
            }
        }

        return $next($request);
    }
}
