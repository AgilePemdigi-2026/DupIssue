<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Test-only subclass: keep Laravel's real token matching,
// but remove its automatic PHPUnit CSRF bypass.
class ExtensionPairingCsrfMiddleware extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

class ExtensionPairingPageTest extends TestCase
{
    use DatabaseMigrations;

    private function reporter(): User
    {
        return User::factory()->create([
            'role' => 'REPORTER',
        ])->refresh();
    }

    public function test_reporter_can_open_pairing_page_without_generating_code(): void
    {
        $response = $this->actingAs($this->reporter())
            ->get(route('extension.connect'))
            ->assertOk()
            ->assertViewIs('extension.connect')
            ->assertViewHas('pairingCode', null)
            ->assertSee('Connect Browser Extension')
            ->assertSee('Buat Pairing Code')
            ->assertSee('name="_token"', false)
            ->assertDontSee('dup_pair_')
            ->assertDontSee('id="extension-pairing-code"', false)
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control')
        );

        $this->assertStringContainsString(
            "default-src 'none'",
            $response->headers->get('Content-Security-Policy')
        );

        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            $response->headers->get('Content-Security-Policy')
        );

        $response->assertDontSee('<script', false);
        $response->assertDontSee('cdn.jsdelivr.net');
        $response->assertDontSee('fonts.googleapis.com');

        $this->assertDatabaseCount(
            'extension_sessions',
            0
        );
    }

    public function test_browser_form_generates_exchangeable_pairing_code(): void
    {
        $reporter = $this->reporter();

        $response = $this->actingAs($reporter)
            ->post(route('extension.pairing.create'))
            ->assertCreated()
            ->assertViewIs('extension.connect')
            ->assertSee('Pairing code Anda')
            ->assertSee(
                'id="extension-pairing-code"',
                false
            )
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Frame-Options', 'DENY');

        $code = $response->viewData('pairingCode');

        $this->assertMatchesRegularExpression(
            '/\Adup_pair_[a-f0-9]{64}\z/',
            $code
        );

        $response->assertSee($code);

        $this->assertStringContainsString(
            'no-store',
            $response->headers->get('Cache-Control')
        );

        $row = DB::table('extension_sessions')->sole();

        $this->assertSame(
            hash('sha256', $code),
            $row->pairing_hash
        );

        $this->assertStringNotContainsString(
            $code,
            json_encode($row, JSON_THROW_ON_ERROR)
        );

        $this->assertStringNotContainsString(
            $code,
            json_encode(session()->all(), JSON_THROW_ON_ERROR)
        );

        $this->assertNull(
            $response->headers->get('Location')
        );

        // The next GET must not redisplay the old credential.
        $this->get(route('extension.connect'))
            ->assertOk()
            ->assertViewHas('pairingCode', null)
            ->assertDontSee($code);

        // The actual extension exchange API accepts the code.
        $this->postJson(
            route('extension.api.exchange'),
            ['pairing_code' => $code]
        )
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer');

        $this->postJson(
            route('extension.api.exchange'),
            ['pairing_code' => $code]
        )->assertUnauthorized();
    }

    public function test_guest_cannot_open_pairing_page(): void
    {
        $this->get(route('extension.connect'))
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount(
            'extension_sessions',
            0
        );
    }

    public function test_maintainer_cannot_open_pairing_page(): void
    {
        $maintainer = User::factory()->create([
            'role' => 'MAINTAINER',
        ])->refresh();

        $this->actingAs($maintainer)
            ->get(route('extension.connect'))
            ->assertForbidden();

        $this->assertDatabaseCount(
            'extension_sessions',
            0
        );
    }

    public function test_pairing_post_enforces_real_csrf_token_matching(): void
    {
        // Bind only in this test. Production middleware is untouched.
        $this->app->bind(
            ValidateCsrfToken::class,
            ExtensionPairingCsrfMiddleware::class
        );

        $token = Str::random(40);

        $this->actingAs($this->reporter())
            ->withSession(['_token' => $token]);

        // Missing token.
        $this->postJson(
            route('extension.pairing.create')
        )->assertStatus(419);

        $this->assertDatabaseCount(
            'extension_sessions',
            0
        );

        // Incorrect token.
        $this->withSession(['_token' => $token])
            ->postJson(
                route('extension.pairing.create'),
                ['_token' => Str::random(40)]
            )
            ->assertStatus(419);

        $this->assertDatabaseCount(
            'extension_sessions',
            0
        );

        // Correct token.
        $this->withSession(['_token' => $token])
            ->postJson(
                route('extension.pairing.create'),
                ['_token' => $token]
            )
            ->assertCreated()
            ->assertJsonPath('expires_in', 300);

        $this->assertDatabaseCount(
            'extension_sessions',
            1
        );
    }

    public function test_pairing_generation_has_its_own_rate_limit(): void
    {
        $this->actingAs($this->reporter());

        // Opening the page does not consume the generation limit.
        for ($i = 0; $i < 6; $i++) {
            $this->get(route('extension.connect'))
                ->assertOk();
        }

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(
                route('extension.pairing.create')
            )->assertCreated();
        }

        $this->postJson(
            route('extension.pairing.create')
        )->assertStatus(429);

        $this->assertDatabaseCount(
            'extension_sessions',
            5
        );

        $this->get(route('extension.connect'))
            ->assertOk();
    }

    public function test_stale_web_session_cannot_generate_pairing_code(): void
    {
        $reporter = $this->reporter();

        $this->actingAs($reporter)
            ->withSession([
                'auth_version' =>
                    (int) $reporter->auth_version - 1,
            ])
            ->post(route('extension.pairing.create'))
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount(
            'extension_sessions',
            0
        );
    }
}
