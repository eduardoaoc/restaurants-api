<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Hard stop before any test touches a database that is not a test
     * database: RefreshDatabase/DatabaseTruncation would wipe it. A cached
     * configuration (bootstrap/cache/config.php or APP_CONFIG_CACHE) or an
     * inherited environment variable can silently override phpunit.xml's
     * DB_DATABASE=testing — this guard does not trust either. Test databases
     * are named `testing` or `*_testing` (see scripts/test-sail.sh and
     * docs/testing-isolated.md).
     */
    protected function setUp(): void
    {
        parent::setUp();

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' && ! preg_match('/(^|_)testing$/', $database)) {
            throw new RuntimeException("Refusing to run tests against database [{$database}]: test databases must be named 'testing' or '*_testing'.");
        }

        if (! app()->environment('testing')) {
            throw new RuntimeException('Refusing to run tests outside APP_ENV=testing.');
        }
    }
}
