<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__).'/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        // Before providers, sessions, or migration traits can open a connection:
        // remove all development connections, including URL/read/write overrides.
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['config']->set([
                'app.env' => 'testing',
                'app.key' => 'base64:'.base64_encode(random_bytes(32)),
                'database.default' => 'sqlite',
                'database.connections' => ['sqlite' => [
                    'driver' => 'sqlite', 'database' => ':memory:',
                    'prefix' => '', 'foreign_key_constraints' => true,
                ]],
                'session.driver' => 'array',
                'cache.default' => 'array',
                'queue.default' => 'sync',
                'queue.connections.database' => [
                    'driver' => 'database', 'connection' => 'sqlite', 'table' => 'jobs',
                    'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
                ],
                'queue.failed' => [
                    'driver' => 'database-uuids', 'database' => 'sqlite', 'table' => 'failed_jobs',
                ],
                'queue.batching.database' => 'sqlite',
            ]);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
