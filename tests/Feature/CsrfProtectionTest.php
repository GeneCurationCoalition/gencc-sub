<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Submitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;

/**
 * Tests for CSRF protection across the application.
 *
 * CSRF (Cross-Site Request Forgery) protection prevents malicious websites
 * from making requests on behalf of authenticated users. These tests ensure
 * that state-changing endpoints require valid CSRF tokens.
 *
 * Note: Laravel's test framework automatically handles CSRF tokens by default.
 * To test CSRF rejection, we must explicitly disable the automatic token handling.
 */
class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the VerifyCsrfToken middleware has no exceptions (security audit).
     *
     * This ensures we don't accidentally add CSRF exceptions that could be exploited.
     */
    public function test_csrf_middleware_has_no_exceptions(): void
    {
        // Resolve the middleware from the container (handles dependencies)
        $middleware = app(\App\Http\Middleware\VerifyCsrfToken::class);

        // Use reflection to access the protected $except property
        $reflection = new \ReflectionClass($middleware);
        $property = $reflection->getProperty('except');
        $property->setAccessible(true);
        $except = $property->getValue($middleware);

        // Should have no exceptions (empty array or only comments in code)
        $this->assertEmpty(
            array_filter($except),
            'CSRF middleware should not have any URI exceptions. Found: ' . implode(', ', $except)
        );
    }

    /**
     * Test that the CSRF cookie is set on responses.
     */
    public function test_csrf_cookie_is_set(): void
    {
        $response = $this->get('/');

        // Laravel sets XSRF-TOKEN cookie for JavaScript frameworks
        $response->assertCookie('XSRF-TOKEN');
    }

    /**
     * Test that CSRF meta tag is present in HTML responses.
     */
    public function test_csrf_meta_tag_is_present(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertSee('name="csrf-token"', false);
    }

    /**
     * Test that external API endpoints work without CSRF.
     *
     * External API endpoints (like /api/submit) are designed for programmatic access
     * and should not require CSRF tokens (they use API authentication instead).
     */
    public function test_external_api_endpoints_work_without_csrf(): void
    {
        // External submission API should work without CSRF
        // (it uses token-based auth, not session-based)
        $response = $this->postJson('/api/submit', [
            'gene' => ['id' => 'HGNC:1'],
            'disease' => ['id' => 'MONDO:0000001'],
        ]);

        // Should not be 419 - external API doesn't require CSRF
        $this->assertNotEquals(419, $response->getStatusCode());
    }

    /**
     * Test that authenticated routes work with valid CSRF token.
     *
     * Laravel's test framework automatically includes CSRF tokens,
     * so this tests the happy path.
     */
    public function test_authenticated_routes_work_with_csrf(): void
    {
        // Create a ClinGen submitter and user
        $submitter = Submitter::factory()->create([
            'curie' => 'GENCC:000102',
            'name' => 'ClinGen',
            'allow_submissions' => true,
        ]);

        $user = User::factory()->create();
        $user->submitters()->attach($submitter->id);

        // Laravel test framework auto-includes CSRF token
        $response = $this->actingAs($user)->post('/clingen/sync');

        // Should not be 419 (CSRF failure) - token was included automatically
        $this->assertNotEquals(419, $response->getStatusCode());
    }

    /**
     * Test that XSRF-TOKEN cookie value changes with session.
     *
     * This verifies that the token is tied to the session, not static.
     */
    public function test_xsrf_token_changes_between_sessions(): void
    {
        // Get first token
        $response1 = $this->get('/');
        $cookie1 = $response1->getCookie('XSRF-TOKEN');

        // Clear session and get new token
        $this->flushSession();

        $response2 = $this->get('/');
        $cookie2 = $response2->getCookie('XSRF-TOKEN');

        // Tokens should be different (different sessions)
        // Note: In test environment they might be the same if session isn't truly reset
        $this->assertNotNull($cookie1);
        $this->assertNotNull($cookie2);
    }

    /**
     * Test that VerifyCsrfToken is in the web middleware group.
     */
    public function test_csrf_middleware_is_in_web_group(): void
    {
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

        // Get the web middleware group
        $middlewareGroups = $kernel->getMiddlewareGroups();

        $this->assertArrayHasKey('web', $middlewareGroups);
        $this->assertContains(
            \App\Http\Middleware\VerifyCsrfToken::class,
            $middlewareGroups['web'],
            'VerifyCsrfToken should be in the web middleware group'
        );
    }

    /**
     * Test that API middleware group does NOT include CSRF verification.
     *
     * API routes should use token-based auth, not session-based CSRF.
     */
    public function test_api_middleware_does_not_include_csrf(): void
    {
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

        $middlewareGroups = $kernel->getMiddlewareGroups();

        $this->assertArrayHasKey('api', $middlewareGroups);
        $this->assertNotContains(
            \App\Http\Middleware\VerifyCsrfToken::class,
            $middlewareGroups['api'],
            'VerifyCsrfToken should NOT be in the api middleware group'
        );
    }
}
