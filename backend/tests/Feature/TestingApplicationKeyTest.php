<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestingApplicationKeyTest extends TestCase
{
    public function test_encryption_key_and_sqlite_isolation_survive_application_refresh(): void
    {
        $environmentKey = getenv('APP_KEY');
        $key = config('app.key');
        $plaintext = 'Encrypted testing fixture survives application refresh.';
        $ciphertext = Crypt::encryptString($plaintext);

        $this->assertStringStartsWith('base64:', $key);
        $this->assertSame(32, strlen(base64_decode(substr($key, 7), true)));
        $this->assertSame($plaintext, Crypt::decryptString($ciphertext));

        $this->refreshApplication();

        $this->assertSame($key, config('app.key'));
        $this->assertSame($plaintext, Crypt::decryptString($ciphertext));
        $this->assertSame($environmentKey, getenv('APP_KEY'));
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(['sqlite'], array_keys(config('database.connections')));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('sqlite', DB::connection()->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertSame('', DB::selectOne('PRAGMA database_list')->file);
    }
}
