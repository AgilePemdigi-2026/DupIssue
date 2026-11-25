<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ExtensionAuthService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExtensionSessionRetentionTest extends TestCase
{
    use DatabaseMigrations;

    public function test_expired_hashes_are_cleared_and_old_metadata_removed_without_affecting_active_tokens(): void
    {
        $auth = app(ExtensionAuthService::class);
        $user = User::factory()->create(['role' => 'REPORTER'])->refresh();
        $auth->createPairingCode($user);
        $this->travel(8)->days();
        $expired = $auth->exchange($auth->createPairingCode($user))['access_token'];
        $this->travel(9)->hours();
        $active = $auth->exchange($auth->createPairingCode($user))['access_token'];
        $this->artisan('extension:sessions-prune')->assertExitCode(0);
        $this->assertDatabaseCount('extension_sessions', 2);
        $this->assertNull($auth->authenticate($expired));
        $this->assertNotNull($auth->authenticate($active));
        $this->assertSame(1, DB::table('extension_sessions')->whereNotNull('access_hash')->count());
        $this->assertSame(0, DB::table('extension_sessions')->whereNotNull('pairing_hash')->count());
    }
}
