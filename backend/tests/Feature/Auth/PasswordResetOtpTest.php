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
}
