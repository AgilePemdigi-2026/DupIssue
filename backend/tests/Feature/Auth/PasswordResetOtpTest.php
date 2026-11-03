<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetOtpNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetOtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_page_has_otp_form(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee('Kirim Kode OTP');
    }

    public function test_existing_reporter_gets_six_digit_code(): void
    {
        Notification::fake();
        $user = User::factory()->create(['role' => 'REPORTER']);
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/verify-otp');

        $notification = Notification::sent($user, PasswordResetOtpNotification::class)->first();
        $this->assertNotNull($notification);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $notification->otp);
        $this->assertDatabaseHas('password_reset_otps', ['email' => $user->email, 'attempts' => 0]);
    }

    public function test_unknown_email_gets_generic_response_without_notification(): void
    {
        Notification::fake();
        $this->post('/forgot-password', ['email' => 'not-registered@example.com'])
            ->assertRedirect('/verify-otp');
        Notification::assertNothingSent();
    }

    public function test_verified_otp_can_reset_password_and_preserve_maintainer_role(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'role' => 'MAINTAINER',
            'password' => 'OldPassword123!',
        ]);

        $this->post('/forgot-password', ['email' => $user->email]);
        $otp = Notification::sent($user, PasswordResetOtpNotification::class)->first()->otp;
        $this->post('/verify-otp', ['otp' => $otp])->assertRedirect('/reset-password');
        $this->get('/reset-password')->assertOk()->assertSee('Password Baru');

        $this->post('/reset-password', [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertRedirect(route('login'));

        $updated = $user->fresh();
        $this->assertTrue(Hash::check('NewPassword123!', $updated->password));
        $this->assertFalse(Hash::check('OldPassword123!', $updated->password));
        $this->assertSame('MAINTAINER', $updated->role);
        $this->assertDatabaseMissing('password_reset_otps', ['email' => $user->email]);
        Notification::assertSentTo($user, PasswordChangedNotification::class);
        $this->get('/reset-password')->assertRedirect('/forgot-password');
        $this->post('/verify-otp', ['otp' => $otp])->assertRedirect('/forgot-password');
    }

    public function test_wrong_code_is_rejected_and_five_attempts_lock_out_code(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        $otp = Notification::sent($user, PasswordResetOtpNotification::class)->first()->otp;

        for ($i = 0; $i < 5; $i++) {
            $this->post('/verify-otp', ['otp' => '000000'])->assertSessionHasErrors('otp');
        }
        $this->post('/verify-otp', ['otp' => $otp])->assertSessionHasErrors('otp');
        $this->assertDatabaseHas('password_reset_otps', ['email' => $user->email, 'attempts' => 5]);
        $this->get('/reset-password')->assertRedirect('/forgot-password');
    }

    public function test_expired_otp_is_rejected(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        $otp = Notification::sent($user, PasswordResetOtpNotification::class)->first()->otp;

        $this->travel(6)->minutes();
        $this->post('/verify-otp', ['otp' => $otp])->assertSessionHasErrors('otp');
    }

    public function test_reset_page_requires_verified_otp(): void
    {
        $this->get('/reset-password')->assertRedirect('/forgot-password');
        $this->post('/reset-password', [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertRedirect('/forgot-password');
    }

    public function test_password_reset_increments_auth_version_and_denies_two_independent_old_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create([
            'role' => 'MAINTAINER',
            'password' => 'OldPassword123!',
        ]);
        $oldVersion = (int) $user->fresh()->auth_version;

        // Two independent, pre-existing browser session stores.
        $browserA = new \Illuminate\Session\Store(
            'browser-a', new \Illuminate\Session\ArraySessionHandler(120)
        );
        $browserB = new \Illuminate\Session\Store(
            'browser-b', new \Illuminate\Session\ArraySessionHandler(120)
        );
        $browserA->start();
        $browserB->start();
        $browserA->put('auth_version', $oldVersion);
        $browserB->put('auth_version', $oldVersion);

        $this->post('/forgot-password', ['email' => $user->email]);
        $otp = Notification::sent($user, PasswordResetOtpNotification::class)->first()->otp;
        $this->post('/verify-otp', ['otp' => $otp])->assertRedirect('/reset-password');
        $this->post('/reset-password', [
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertRedirect(route('login'));

        $updated = $user->fresh();
        $this->assertSame($oldVersion + 1, (int) $updated->auth_version);
        $this->assertTrue(Hash::check('NewPassword123!', $updated->password));

        $middleware = app(\App\Http\Middleware\EnforceAuthVersion::class);
        foreach ([$browserA, $browserB] as $oldBrowser) {
            $request = \Illuminate\Http\Request::create('/maintainer/dashboard', 'GET');
            $request->setLaravelSession($oldBrowser);
            $request->setUserResolver(fn () => $updated);

            $response = $middleware->handle($request, fn () => response('SECURITY_BYPASS'));
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame(route('login'), $response->headers->get('Location'));
            $this->assertNull($oldBrowser->get('auth_version'));
        }

        // New session created after reset has the current version.
        $browserNew = new \Illuminate\Session\Store(
            'browser-new', new \Illuminate\Session\ArraySessionHandler(120)
        );
        $browserNew->start();
        $browserNew->put('auth_version', (int) $updated->auth_version);
        $request = \Illuminate\Http\Request::create('/maintainer/dashboard', 'GET');
        $request->setLaravelSession($browserNew);
        $request->setUserResolver(fn () => $updated);
        $response = $middleware->handle($request, fn () => response('ALLOWED'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_missing_session_version_does_not_silently_authorize_a_persisted_login(): void
    {
        $user = User::factory()->create(['role' => 'MAINTAINER']);
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();
        $this->assertSame(1, (int) session('auth_version'));

        // Real session identifier exists; missing version must fail closed.
        $this->withSession(['auth_version' => null]);
        $this->get('/maintainer/dashboard')->assertRedirect(route('login'));
    }

    public function test_resend_is_limited_and_existing_and_unknown_emails_see_generic_status(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $first = $this->post('/forgot-password', ['email' => $user->email]);
        $message = session('status');
        $this->assertNotEmpty($message);
        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', $message);
        Notification::assertSentToTimes($user, PasswordResetOtpNotification::class, 1);

        $this->post('/forgot-password', ['email' => 'unknown-otp@example.test'])
            ->assertSessionHas('status', $message);
    }
}
