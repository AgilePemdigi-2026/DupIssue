<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // Satu key testing per proses, dipakai ulang oleh semua refresh aplikasi.
    private static ?string $testingKey = null;

    public function createApplication()
    {
        $app = parent::createApplication();

        if (! $app->environment('testing') || $app->configurationIsCached()) {
            throw new \RuntimeException('Tests require an uncached testing environment.');
        }

        // Applied before RefreshDatabase, including tests that override its hooks.
        $app['config']->set([
            'database.default' => 'sqlite',
            'database.connections' => [
                'sqlite' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
            ],
            'session.driver' => 'array',
            'cache.default' => 'array',
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'app.key' => self::$testingKey ??= 'base64:'.base64_encode(random_bytes(32)),
        ]);
        DB::purge('sqlite');
        $connection = DB::connection();
        if ($connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || $connection->getDatabaseName() !== ':memory:'
            || $connection->selectOne('PRAGMA database_list')->file !== '') {
            throw new \RuntimeException('Refusing to run tests outside SQLite memory.');
        }

        return $app;
    }
}
