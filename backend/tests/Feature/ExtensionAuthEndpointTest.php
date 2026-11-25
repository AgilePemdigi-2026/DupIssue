<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ExtensionAuthService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ExtensionAuthEndpointTest extends TestCase
{
    use DatabaseMigrations;

    private function reporter(): User
    {
        return User::factory()->create([
            'role' => 'REPORTER',
        ])->refresh();
    }

    private function token(User $reporter): string
    {
        $service = app(ExtensionAuthService::class);

        $result = $service->exchange(
            $service->createPairingCode($reporter)
        );

        $this->assertNotNull($result);

        return $result['access_token'];
    }

    public function test_reporter_can_pair_authenticate_and_disconnect(): void
    {
        $reporter = $this->reporter();

        $pairing = $this->actingAs($reporter)
            ->postJson(route('extension.pairing.create'))
            ->assertCreated()
            ->assertJsonPath('expires_in', 300);

        $this->assertStringContainsString(
            'no-store',
            $pairing->headers->get('Cache-Control')
        );

        $code = $pairing->json('pairing_code');

        $this->assertMatchesRegularExpression(
            '/\Adup_pair_[a-f0-9]{64}\z/',
            $code
        );

        $exchange = $this->postJson(
            route('extension.api.exchange'),
            ['pairing_code' => $code]
        )
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_at',
            ]);

        $this->assertStringContainsString(
            'no-store',
            $exchange->headers->get('Cache-Control')
        );

        $token = $exchange->json('access_token');

        $this->assertMatchesRegularExpression(
            '/\Adup_ext_[a-f0-9]{64}\z/',
            $token
        );

        // Pairing code cannot be exchanged twice.
        $this->postJson(
            route('extension.api.exchange'),
            ['pairing_code' => $code]
        )
            ->assertUnauthorized()
            ->assertJsonPath(
                'code',
                'INVALID_PAIRING_CODE'
            );

        $me = $this->getJson(
            route('extension.api.me'),
            ['Authorization' => 'Bearer '.$token]
        )
            ->assertOk()
            ->assertExactJson([
                'user' => [
                    'id' => $reporter->id,
                    'name' => $reporter->name,
                    'role' => 'REPORTER',
                ],
            ]);

        $this->assertStringContainsString(
            'no-store',
            $me->headers->get('Cache-Control')
        );

        $this->deleteJson(
            route('extension.api.disconnect'),
            [],
            ['Authorization' => 'Bearer '.$token]
        )
            ->assertOk()
            ->assertExactJson([
                'disconnected' => true,
            ]);

        $this->getJson(
            route('extension.api.me'),
            ['Authorization' => 'Bearer '.$token]
        )
            ->assertUnauthorized()
            ->assertJsonPath(
                'code',
                'UNAUTHENTICATED'
            );

        $row = DB::table('extension_sessions')->sole();

        $this->assertNull($row->pairing_hash);
        $this->assertNull($row->access_hash);
        $this->assertNotNull($row->revoked_at);
    }

    public function test_guest_cannot_generate_pairing_code(): void
    {
        $this->postJson(
            route('extension.pairing.create')
        )->assertUnauthorized();

        $this->assertDatabaseCount(
            'extension_sessions',
            0
        );
    }

    public function test_maintainer_cannot_generate_pairing_code(): void
    {
        $maintainer = User::factory()->create([
            'role' => 'MAINTAINER',
        ])->refresh();

        $this->actingAs($maintainer)
            ->postJson(
                route('extension.pairing.create')
            )
            ->assertForbidden();

        $this->assertDatabaseCount(
            'extension_sessions',
            0
        );
    }

    public function test_web_session_alone_cannot_access_extension_api(): void
    {
        $reporter = $this->reporter();

        $this->actingAs($reporter)
            ->getJson(route('extension.api.me'))
            ->assertUnauthorized()
            ->assertJsonPath(
                'code',
                'UNAUTHENTICATED'
            );

        $this->deleteJson(
            route('extension.api.disconnect')
        )->assertUnauthorized();
    }

    public function test_bearer_token_does_not_create_web_session(): void
    {
        $reporter = $this->reporter();
        $token = $this->token($reporter);

        $headers = [
            'Authorization' => 'Bearer '.$token,
        ];

        $this->getJson(
            route('extension.api.me'),
            $headers
        )
            ->assertOk()
            ->assertJsonPath(
                'user.id',
                $reporter->id
            );

        $this->assertGuest('web');

        // A valid extension token cannot authorize
        // a web-session-only operation.
        $this->postJson(
            route('extension.pairing.create'),
            [],
            $headers
        )->assertUnauthorized();

        $this->assertDatabaseCount(
            'extension_sessions',
            1
        );
    }

    public function test_invalid_and_query_string_credentials_are_rejected(): void
    {
        $reporter = $this->reporter();

        $code = app(ExtensionAuthService::class)
            ->createPairingCode($reporter);

        $invalid = $this->postJson(
            route('extension.api.exchange'),
            ['pairing_code' => ['invalid']]
        )->assertUnauthorized();

        $this->assertStringContainsString(
            'no-store',
            $invalid->headers->get('Cache-Control')
        );

        $this->postJson(
            route('extension.api.exchange')
                .'?pairing_code='.$code,
            []
        )->assertUnauthorized();

        $this->getJson(
            route('extension.api.me'),
            ['Authorization' => 'Bearer invalid-token']
        )->assertUnauthorized();

        $this->getJson(
            route('extension.api.me'),
            ['Authorization' => 'Bearer '.$code]
        )->assertUnauthorized();

        $this->assertDatabaseHas(
            'extension_sessions',
            [
                'pairing_hash' => hash('sha256', $code),
                'paired_at' => null,
                'access_hash' => null,
            ]
        );

        // Failed requests did not consume the valid code.
        $this->postJson(
            route('extension.api.exchange'),
            ['pairing_code' => $code]
        )->assertOk();
    }

    public function test_password_reset_invalidates_extension_api_access(): void
    {
        $reporter = $this->reporter();
        $token = $this->token($reporter);

        $headers = [
            'Authorization' => 'Bearer '.$token,
        ];

        $this->getJson(
            route('extension.api.me'),
            $headers
        )->assertOk();

        DB::table('users')
            ->where('id', $reporter->id)
            ->increment('auth_version');

        $this->getJson(
            route('extension.api.me'),
            $headers
        )->assertUnauthorized();

        $this->deleteJson(
            route('extension.api.disconnect'),
            [],
            $headers
        )->assertUnauthorized();
    }

    public function test_pairing_exchange_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson(
                route('extension.api.exchange'),
                ['pairing_code' => 'invalid']
            )->assertUnauthorized();
        }

        $this->postJson(
            route('extension.api.exchange'),
            ['pairing_code' => 'invalid']
        )->assertStatus(429);

        $this->assertDatabaseCount(
            'extension_sessions',
            0
        );
    }

    public function test_web_pairing_retains_csrf_middleware_and_api_is_separate(): void
    {
        // Laravel registers middleware groups and aliases when
        // the HTTP kernel is resolved. This route-inspection test
        // does not execute an HTTP request beforehand.
        $this->app->make(
            \Illuminate\Contracts\Http\Kernel::class
        );

        $groups = app('router')->getMiddlewareGroups();

        $this->assertArrayHasKey('web', $groups);
        $this->assertArrayHasKey('api', $groups);

        $this->assertNotEmpty(
            $groups['web'],
            'HTTP kernel must register the web middleware group.'
        );

        $web = Route::getRoutes()->getByName(
            'extension.pairing.create'
        );

        $api = Route::getRoutes()->getByName(
            'extension.api.exchange'
        );

        $this->assertNotNull($web);
        $this->assertNotNull($api);

        $webMiddleware = app('router')
            ->gatherRouteMiddleware($web);

        $apiMiddleware = app('router')
            ->gatherRouteMiddleware($api);

        // Laravel versions may register different concrete
        // implementations of CSRF/request-forgery protection.
        $isCsrfMiddleware = static function (
            string $middleware
        ): bool {
            $class = explode(':', $middleware, 2)[0];

            $implementations = [
                \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
                \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
            ];

            if (! class_exists($class)) {
                return false;
            }

            foreach ($implementations as $implementation) {
                if (
                    class_exists($implementation)
                    && is_a($class, $implementation, true)
                ) {
                    return true;
                }
            }

            return false;
        };

        $webCsrf = array_values(
            array_filter($webMiddleware, $isCsrfMiddleware)
        );

        $apiCsrf = array_values(
            array_filter($apiMiddleware, $isCsrfMiddleware)
        );

        fwrite(
            STDOUT,
            "\nWEB PAIRING MIDDLEWARE:\n".
            implode("\n", $webMiddleware).
            "\n\nEXTENSION API MIDDLEWARE:\n".
            implode("\n", $apiMiddleware).
            "\n"
        );

        // Do not weaken the security requirement:
        // web pairing must have real CSRF protection.
        $this->assertNotEmpty(
            $webCsrf,
            'Web pairing must retain Laravel CSRF protection.'
        );

        // Bearer-token API remains separate from web sessions.
        $this->assertSame(
            [],
            $apiCsrf,
            'Extension exchange must remain stateless.'
        );

        fwrite(
            STDOUT,
            "\nACTIVE WEB CSRF MIDDLEWARE: ".
            implode(', ', $webCsrf).
            "\n"
        );

        // The web route must keep session authentication
        // and password-reset session invalidation.
        $this->assertContains(
            \Illuminate\Session\Middleware\StartSession::class,
            $webMiddleware
        );

        $this->assertContains(
            \App\Http\Middleware\EnforceAuthVersion::class,
            $webMiddleware
        );

        // Stateless API requests must not create web sessions.
        $this->assertNotContains(
            \Illuminate\Session\Middleware\StartSession::class,
            $apiMiddleware
        );

        $this->assertNotContains(
            \App\Http\Middleware\EnforceAuthVersion::class,
            $apiMiddleware
        );

        $this->assertContains(
            'web',
            $web->gatherMiddleware()
        );

        $this->assertContains(
            'api',
            $api->gatherMiddleware()
        );

        $this->assertNotContains(
            'web',
            $api->gatherMiddleware()
        );
    }
}
