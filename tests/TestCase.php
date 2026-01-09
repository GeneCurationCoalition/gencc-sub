<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Boot the testing environment.
     *
     * CRITICAL DATABASE SAFETY CHECK:
     * Prevents RefreshDatabase from running against production database
     */
    protected function setUp(): void
    {
        parent::setUp();

        // SAFETY CHECK 1: Verify we're in testing environment
        if (app()->environment() !== 'testing') {
            throw new \RuntimeException(
                "CRITICAL: Tests are trying to run in '" . app()->environment() . "' environment! " .
                "Tests MUST run in 'testing' environment to prevent production database damage. " .
                "Check your .env file and phpunit.xml configuration."
            );
        }

        // SAFETY CHECK 2: Verify database connection is SQLite in-memory
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(
                "CRITICAL: Tests are NOT using SQLite in-memory database! " .
                "Current connection: {$connection}, Database: {$database}. " .
                "This could wipe your production database. " .
                "Tests MUST use 'sqlite' connection with ':memory:' database. " .
                "Check phpunit.xml lines 24-25."
            );
        }

        // SAFETY CHECK 3: Verify we're not connected to production database names
        $productionDatabases = ['gencc_sub', 'gencc_search', 'production', 'prod'];
        if (in_array(strtolower($database), array_map('strtolower', $productionDatabases))) {
            throw new \RuntimeException(
                "CRITICAL: Tests are connected to production database '{$database}'! " .
                "This would destroy production data. " .
                "Tests MUST use ':memory:' SQLite database."
            );
        }

        // Clear rate limiters to prevent 429 errors between test classes
        // The rate limiter key format is "api:{user_id}" or "api:{ip}", so clear both
        RateLimiter::clear('api');
        RateLimiter::clear('api:127.0.0.1');
        RateLimiter::clear('api:1'); // User ID 1 is commonly used in tests

        // Also clear any cache-based rate limiters
        if (app()->bound('cache')) {
            try {
                app('cache')->flush();
            } catch (\Exception $e) {
                // Ignore cache flush errors in tests
            }
        }
    }
}
