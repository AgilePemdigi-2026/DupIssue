<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ExtensionAuthService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ExtensionAuthServiceTest extends TestCase
{
    use DatabaseMigrations;

    private function reporter(): User
    {
        $reporter = User::factory()->create([
            'role' => 'REPORTER',
        ]);

        $persisted = $reporter->fresh();

        $this->assertNotNull($persisted);
        $this->assertSame('REPORTER', $persisted->role);
        $this->assertNotNull($persisted->auth_version);

        // Inspect only non-sensitive authentication metadata.
        fwrite(
            STDOUT,
            sprintf(
                "\nAUTH FIXTURE: model auth_version=%s, database auth_version=%s\n",
                var_export($reporter->auth_version, true),
                var_export($persisted->auth_version, true)
            )
        );

        // Eloquent models created by a factory do not necessarily
        // contain database-generated default attributes.
        return $reporter->refresh();
    }

    private function service(): ExtensionAuthService
    {
        return app(ExtensionAuthService::class);
    }

    private function issueToken(User $user): string
    {
        $code = $this->service()->createPairingCode($user);

        $result = $this->service()->exchange($code);

        $this->assertNotNull($result);

        return $result['access_token'];
    }

    public function test_pairing_is_single_use_and_credentials_are_hashed(): void
    {
        $this->freezeTime();

        $reporter = $this->reporter();

        $code = $this->service()
            ->createPairingCode($reporter);

        $this->assertMatchesRegularExpression(
            '/\Adup_pair_[a-f0-9]{64}\z/',
            $code
        );

        $row = DB::table('extension_sessions')->sole();

        $this->assertSame(
            hash('sha256', $code),
            $row->pairing_hash
        );

        $this->assertSame(
            (int) $reporter->id,
            (int) $row->user_id
        );

        $this->assertSame(
            (int) $reporter->auth_version,
            (int) $row->auth_version
        );

        $this->assertNull($row->access_hash);

        $this->assertStringNotContainsString(
            $code,
            json_encode($row, JSON_THROW_ON_ERROR)
        );

        $this->assertNull(
            $this->service()->authenticate($code)
        );

        $result = $this->service()->exchange($code);

        $this->assertNotNull($result);

        $token = $result['access_token'];

        $this->assertMatchesRegularExpression(
            '/\Adup_ext_[a-f0-9]{64}\z/',
            $token
        );

        $this->assertSame(
            now()->addHours(8)->toIso8601String(),
            $result['expires_at']
        );

        $paired = DB::table('extension_sessions')->sole();

        $this->assertNull($paired->pairing_hash);
        $this->assertNotNull($paired->paired_at);

        $this->assertSame(
            hash('sha256', $token),
            $paired->access_hash
        );

        $this->assertStringNotContainsString(
            $token,
            json_encode($paired, JSON_THROW_ON_ERROR)
        );

        $this->assertNull(
            $this->service()->exchange($code)
        );

        $this->assertNull(
            $this->service()->exchange($token)
        );

        $this->assertSame(
            $reporter->id,
            $this->service()->authenticate($token)?->id
        );

        $this->assertDatabaseCount('extension_sessions', 1);
    }

    public function test_pairing_code_expires_at_five_minutes(): void
    {
        $this->freezeTime();

        $reporter = $this->reporter();

        $code = $this->service()
            ->createPairingCode($reporter);

        $this->travel(5)->minutes();

        $this->assertNull(
            $this->service()->exchange($code)
        );

        $this->assertDatabaseHas('extension_sessions', [
            'user_id' => $reporter->id,
            'paired_at' => null,
            'access_hash' => null,
        ]);

        $this->travelBack();
    }

    public function test_access_token_expires_at_eight_hours(): void
    {
        $this->freezeTime();

        $reporter = $this->reporter();

        $token = $this->issueToken($reporter);

        $this->assertSame(
            $reporter->id,
            $this->service()->authenticate($token)?->id
        );

        $this->travel(8)->hours();

        $this->assertNull(
            $this->service()->authenticate($token)
        );

        $this->travelBack();
    }

    public function test_revocation_only_invalidates_presented_session(): void
    {
        $reporter = $this->reporter();

        $first = $this->issueToken($reporter);
        $second = $this->issueToken($reporter);

        $this->assertNotSame($first, $second);

        $this->service()->revoke($first);
        $this->service()->revoke($first);

        $this->assertNull(
            $this->service()->authenticate($first)
        );

        $this->assertSame(
            $reporter->id,
            $this->service()->authenticate($second)?->id
        );

        $this->assertSame(
            1,
            DB::table('extension_sessions')
                ->whereNotNull('revoked_at')
                ->whereNull('access_hash')
                ->count()
        );

        $this->assertSame(
            1,
            DB::table('extension_sessions')
                ->whereNull('revoked_at')
                ->count()
        );
    }

    public function test_auth_version_change_invalidates_pending_and_active_credentials(): void
    {
        $reporter = $this->reporter();

        $pending = $this->service()
            ->createPairingCode($reporter);

        $token = $this->issueToken($reporter);

        // Existing password-reset mechanism rotates auth_version.
        DB::table('users')
            ->where('id', $reporter->id)
            ->increment('auth_version');

        $this->assertNull(
            $this->service()->exchange($pending)
        );

        $this->assertNull(
            $this->service()->authenticate($token)
        );

        // Fresh authentication may establish a new session.
        $freshToken = $this->issueToken(
            $reporter->fresh()
        );

        $this->assertSame(
            $reporter->id,
            $this->service()->authenticate($freshToken)?->id
        );
    }

    public function test_stale_actor_cannot_create_pairing_code(): void
    {
        $reporter = $this->reporter();

        $originalVersion = (int) $reporter->auth_version;

        // Simulate the existing password-reset invalidation.
        DB::table('users')
            ->where('id', $reporter->id)
            ->increment('auth_version');

        $this->assertSame(
            $originalVersion,
            (int) $reporter->auth_version
        );

        $this->assertSame(
            $originalVersion + 1,
            (int) $reporter->fresh()->auth_version
        );

        // The stale object must remain unauthorized.
        try {
            $this->service()
                ->createPairingCode($reporter);

            $this->fail(
                'Expected stale authentication rejection.'
            );
        } catch (HttpException $exception) {
            $this->assertSame(
                403,
                $exception->getStatusCode()
            );
        }

        $this->assertDatabaseCount(
            'extension_sessions',
            0
        );

        // A newly authenticated actor with the current version
        // may establish an extension session.
        $freshReporter = $reporter->fresh();

        $code = $this->service()
            ->createPairingCode($freshReporter);

        $result = $this->service()->exchange($code);

        $this->assertNotNull($result);

        $this->assertSame(
            $reporter->id,
            $this->service()
                ->authenticate($result['access_token'])?->id
        );

        $this->assertDatabaseCount(
            'extension_sessions',
            1
        );
    }

    public function test_maintainer_cannot_create_pairing_code(): void
    {
        $maintainer = User::factory()->create([
            'role' => 'MAINTAINER',
        ]);

        try {
            $this->service()
                ->createPairingCode($maintainer);

            $this->fail('Expected authorization failure.');
        } catch (HttpException $exception) {
            $this->assertSame(
                403,
                $exception->getStatusCode()
            );
        }

        $this->assertDatabaseCount('extension_sessions', 0);
    }

    public function test_role_change_invalidates_extension_credentials(): void
    {
        $reporter = $this->reporter();

        $pending = $this->service()
            ->createPairingCode($reporter);

        $token = $this->issueToken($reporter);

        DB::table('users')
            ->where('id', $reporter->id)
            ->update(['role' => 'MAINTAINER']);

        $this->assertNull(
            $this->service()->exchange($pending)
        );

        $this->assertNull(
            $this->service()->authenticate($token)
        );
    }

    public function test_malformed_and_unknown_credentials_are_rejected(): void
    {
        $reporter = $this->reporter();

        $token = $this->issueToken($reporter);

        $invalidCredentials = [
            '',
            'invalid-token',
            'dup_ext_'.str_repeat('a', 63),
            'dup_ext_'.str_repeat('G', 64),
            'dup_ext_'.str_repeat('b', 64),
            'dup_pair_'.str_repeat('c', 64),
            str_repeat('x', 10000),
        ];

        foreach ($invalidCredentials as $credential) {
            $this->assertNull(
                $this->service()->authenticate($credential)
            );

            $this->assertNull(
                $this->service()->exchange($credential)
            );

            $this->service()->revoke($credential);
        }

        // Invalid requests must not affect a legitimate session.
        $this->assertSame(
            $reporter->id,
            $this->service()->authenticate($token)?->id
        );

        $this->assertDatabaseCount('extension_sessions', 1);
    }
}
