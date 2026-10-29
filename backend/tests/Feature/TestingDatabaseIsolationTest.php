<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestingDatabaseIsolationTest extends TestCase
{
    public function test_only_in_memory_sqlite_is_available_before_migrations(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(['sqlite'], array_keys(config('database.connections')));
        $this->assertSame('sqlite', config('database.default'));
        $connection = DB::connection();
        $this->assertSame('sqlite', $connection->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertSame(':memory:', $connection->getDatabaseName());
        $this->assertSame('', $connection->selectOne('PRAGMA database_list')->file);
        $this->assertSame('sqlite', config('queue.connections.database.connection'));
        $this->assertSame('sqlite', config('queue.failed.database'));
    }
}
