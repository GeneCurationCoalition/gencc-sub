<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // No exceptions - all state-changing requests require CSRF protection.
        // Admin routes use Sanctum's EnsureFrontendRequestsAreStateful which
        // automatically handles CSRF for stateful (browser) requests.
    ];
}
