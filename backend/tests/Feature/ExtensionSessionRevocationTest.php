<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ExtensionAuthService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RevocationCsrfMiddleware extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

class ExtensionSessionRevocationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_owner_can_revoke_lost_session_without_exposing_credentials(): void
    {
        $user = User::factory()->create(['role' => 'REPORTER'])->refresh();
        $auth = app(ExtensionAuthService::class);
        $code = $auth->createPairingCode($user);
        $token = $auth->exchange($code)['access_token'];
        $row = DB::table('extension_sessions')->first();
        $this->actingAs($user)->get(route('extension.connect'))->assertOk()
            ->assertSee('Cabut sesi #'.$row->id)->assertDontSee($token)->assertDontSee($row->access_hash);
        $this->delete(route('extension.sessions.revoke', $row->id))->assertRedirect(route('extension.connect'));
        $this->assertNull($auth->authenticate($token));
        $this->delete(route('extension.sessions.revoke', $row->id))->assertRedirect();
    }

    public function test_other_reporter_cannot_view_or_revoke_session(): void
    {
        $owner = User::factory()->create(['role' => 'REPORTER'])->refresh();
        $auth = app(ExtensionAuthService::class);
        $code = $auth->createPairingCode($owner);
        $row = DB::table('extension_sessions')->first();
        $other = User::factory()->create(['role' => 'REPORTER'])->refresh();
        $this->actingAs($other)->get(route('extension.connect'))->assertDontSee('Cabut sesi #'.$row->id);
        $this->delete(route('extension.sessions.revoke', $row->id))->assertNotFound();
        $this->assertNotNull($auth->exchange($code));
    }

    public function test_revocation_requires_web_auth_role_and_real_csrf(): void
    {
        $user = User::factory()->create(['role' => 'REPORTER'])->refresh();
        app(ExtensionAuthService::class)->createPairingCode($user);
        $id = DB::table('extension_sessions')->value('id');
        $url = route('extension.sessions.revoke', $id);
        $this->delete($url)->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'MAINTAINER']))->delete($url)->assertForbidden();
        $this->app->bind(ValidateCsrfToken::class, RevocationCsrfMiddleware::class);
        $this->actingAs($user)->withSession(['_token' => 'test-only-csrf'])->delete($url)->assertStatus(419);
        $this->withSession(['_token' => 'test-only-csrf'])->delete($url, ['_token' => 'test-only-csrf'])->assertRedirect();
        $this->assertNull(DB::table('extension_sessions')->value('pairing_hash'));
    }
}
