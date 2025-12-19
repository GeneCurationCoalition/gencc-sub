<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        // CRITICAL: Force testing environment AND SQLite database BEFORE Laravel loads .env
        // This ensures tests NEVER touch the production MySQL database

        // Derive a deterministic APP_KEY for tests from some known seed
        $appKey = 'base64:' . base64_encode(hash('sha256', 'test-key-seed', binary: true));

        putenv('APP_ENV=testing');
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        putenv("APP_KEY=$appKey");
        putenv('BCRYPT_ROUNDS=4');

        $_ENV['APP_ENV'] = 'testing';
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_ENV['APP_KEY'] = $appKey;
        $_ENV['BCRYPT_ROUNDS'] = '4';

        $_SERVER['APP_ENV'] = 'testing';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';
        $_SERVER['APP_KEY'] = $appKey;
        $_SERVER['BCRYPT_ROUNDS'] = '4';

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
