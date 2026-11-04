<?php

declare(strict_types=1);

use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetOtpNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Soulcodex\Behat\Addon\Context;

/**
 * Executable Login/RBAC and OTP acceptance steps.
 *
 * FeatureContext (PR #35) initializes Laravel and migrates ONLY guarded SQLite
 * :memory:. Email notifications are faked BEFORE calling any OTP endpoint.
 * Production controllers, mail transports and the development DB are untouched.
 */
final class AuthBddContext extends Context
{
    private const OLD_PASSWORD = 'BddAuthOldPassword123!';
    private const NEW_PASSWORD = 'BddAuthNewPassword456!';

    private ?User $subject = null;
    private ?string $otp = null;
    private ?string $originalRole = null;
    private ?int $originalAuthVersion = null;

    /** @BeforeScenario */
    public function resetAuthBddState(): void
    {
        $this->subject = null;
        $this->otp = null;
        $this->originalRole = null;
        $this->originalAuthVersion = null;
        Carbon::setTestNow();
    }

    /** @AfterScenario */
    public function restoreClock(): void
    {
        Carbon::setTestNow();
    }

    /** @Given I have an auth test account with role :role */
    public function iHaveAuthTestAccount(string $role): void
    {
        $this->requireSafeEnvironment();
        $role = strtoupper($role);
        if (! in_array($role, ['REPORTER', 'MAINTAINER'], true)) {
            throw new RuntimeException('Unexpected role: '.$role);
        }

        $this->subject = User::factory()->create([
            'name' => 'BDD '.$role,
            'email' => $this->uniqueEmail('login'),
            'role' => $role,
            'password' => self::OLD_PASSWORD,
        ]);
        $this->originalRole = $role;
        $this->originalAuthVersion = (int) $this->subject->fresh()->auth_version;
    }

    /** @Given I am an anonymous auth test visitor */
    public function iAmAnonymousAuthVisitor(): void
    {
        $this->requireSafeEnvironment();
        if (Auth::check()) {
            throw new RuntimeException('Expected a guest after BDD scenario isolation.');
        }
    }

    /** @When I log in with the correct auth test password */
    public function iLogInWithCorrectPassword(): void
    {
        $this->submitLogin(self::OLD_PASSWORD);
    }

    /** @When I log in with an incorrect auth test password */
    public function iLogInWithWrongPassword(): void
    {
        $this->submitLogin('IncorrectBddPassword999!');
    }

    /** @Then the auth test user is authenticated */
    public function theAuthTestUserIsAuthenticated(): void
    {
        if (! Auth::check() || (int) Auth::id() !== (int) $this->requireSubject()->id) {
            throw new RuntimeException('Expected authentication as the BDD user.');
        }
    }

    /** @Then I land on the :role home page */
    public function iLandOnTheRoleHomePage(string $role): void
    {
        $expected = match (strtoupper($role)) {
            'REPORTER' => '/reporter/projects',
            'MAINTAINER' => '/maintainer/dashboard',
            default => throw new RuntimeException('Unknown role in redirect assertion.'),
        };
        $this->assertCurrentPath($expected);
    }

    /** @Then the auth test login is rejected with a validation message */
    public function loginIsRejectedWithError(): void
    {
        if (Auth::check()) {
            throw new RuntimeException('Wrong password incorrectly authenticated the user.');
        }
        $this->assertCurrentPath('/login');
        if (! str_contains($this->session()->getPage()->getText(), 'Email atau kata sandi salah.')) {
            throw new RuntimeException('Expected a visible invalid-credentials message.');
        }
    }

    /** @When I open the :role restricted page */
    public function iOpenRestrictedPage(string $role): void
    {
        $path = match (strtoupper($role)) {
            'REPORTER' => '/reporter/projects',
            'MAINTAINER' => '/maintainer/dashboard',
            default => throw new RuntimeException('Unknown restricted role.'),
        };
        $this->visitUrl($path);
    }

    /** @Then the auth test visitor is sent to login */
    public function guestSentToLogin(): void
    {
        $this->assertCurrentPath('/login');
        if (Auth::check()) {
            throw new RuntimeException('A guest must not have an authenticated session.');
        }
    }

    /** @Then the auth test request is forbidden */
    public function theAuthRequestIsForbidden(): void
    {
        $status = $this->session()->getStatusCode();
        if ($status !== 403) {
            throw new RuntimeException('Expected HTTP 403, received '.$status.'.');
        }
    }

    /** @Given I have an OTP-test Reporter account */
    public function iHaveOtpReporterAccount(): void
    {
        $this->requireSafeEnvironment();

        // No actual email can be sent. The fake captures queued notifications too.
        Notification::fake();
        // The production OTP controller intentionally refuses 'array' and 'log'
        // mailers outside PHPUnit. A fake notification is already installed, so
        // presenting a non-log transport passes that configuration check safely.
        config(['mail.default' => 'smtp']);

        $this->subject = User::factory()->create([
            'name' => 'BDD OTP Reporter',
            'email' => $this->uniqueEmail('otp'),
            'role' => 'REPORTER',
            'password' => self::OLD_PASSWORD,
        ]);
        $this->originalRole = 'REPORTER';
        $this->originalAuthVersion = (int) $this->subject->fresh()->auth_version;
    }

    /** @When I request an OTP through the forgot-password form */
    public function iRequestOtpViaForm(): void
    {
        $this->requireSafeEnvironment();
        $user = $this->requireSubject();
        $this->visitUrl('/forgot-password');
        $this->submitFirstForm(['email' => $user->email]);
        $this->assertCurrentPath('/verify-otp');
        $this->captureOtp();
    }

    /** @Then the OTP-test user has received a six-digit notification */
    public function otpNotificationIsSixDigits(): void
    {
        $user = $this->requireSubject();
        $otp = $this->requireOtp();
        if (! preg_match('/^[0-9]{6}$/', $otp)) {
            throw new RuntimeException('Expected a six-digit OTP.');
        }
        $notifications = Notification::sent($user, PasswordResetOtpNotification::class);
        if ($notifications->count() !== 1) {
            throw new RuntimeException('Expected exactly one faked OTP notification.');
        }
        $row = DB::table('password_reset_otps')->where('email', $user->email)->first();
        if (! $row || (int) $row->attempts !== 0) {
            throw new RuntimeException('Expected a stored OTP verification record.');
        }
        if (! hash_equals(
            (string) $row->code_hash,
            hash_hmac('sha256', $user->email.'|'.$otp, (string) config('app.key'))
        )) {
            throw new RuntimeException('The stored OTP digest is incorrect.');
        }
    }

    /** @When I verify the OTP and set a new password */
    public function iVerifyOtpAndResetPassword(): void
    {
        $this->requireSafeEnvironment();
        $this->submitFirstForm(['otp' => $this->requireOtp()]);
        $this->assertCurrentPath('/reset-password');
        $this->submitFirstForm([
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);
        $this->assertCurrentPath('/login');
    }

    /** @Then the OTP-test password is changed and the role is preserved */
    public function passwordChangedAndRolePreserved(): void
    {
        $user = $this->requireSubject()->fresh();
        if (! $user || ! Hash::check(self::NEW_PASSWORD, $user->password)
            || Hash::check(self::OLD_PASSWORD, $user->password)) {
            throw new RuntimeException('Password reset did not persist the correct password.');
        }
        if ($user->role !== $this->originalRole) {
            throw new RuntimeException('Password reset changed the user role.');
        }
        if ((int) $user->auth_version !== $this->originalAuthVersion + 1) {
            throw new RuntimeException('Password reset did not revoke old session versions.');
        }
        if (DB::table('password_reset_otps')->where('email', $user->email)->exists()) {
            throw new RuntimeException('Used OTP/grant record was not removed.');
        }
        Notification::assertSentTo($user, PasswordChangedNotification::class);
    }

    /** @When I verify an incorrect OTP */
    public function iVerifyAnIncorrectOtp(): void
    {
        $incorrect = $this->requireOtp() === '000000' ? '999999' : '000000';
        $this->submitFirstForm(['otp' => $incorrect]);
    }

    /** @When I move the OTP test clock forward six minutes */
    public function iAdvanceOtpClock(): void
    {
        $this->requireOtp();
        Carbon::setTestNow(now()->addMinutes(6));
    }

    /** @When I try to verify the OTP */
    public function iTryToVerifyOtp(): void
    {
        $this->submitFirstForm(['otp' => $this->requireOtp()]);
    }

    /** @Then the OTP-test verification is denied */
    public function otpVerificationIsDenied(): void
    {
        $this->assertCurrentPath('/verify-otp');
        $text = $this->session()->getPage()->getText();
        if (! str_contains($text, 'Kode OTP salah, kedaluwarsa, sudah digunakan')) {
            throw new RuntimeException('Expected a visible OTP rejection message.');
        }
        $row = DB::table('password_reset_otps')
            ->where('email', $this->requireSubject()->email)->first();
        if (! $row || $row->verified_at !== null) {
            throw new RuntimeException('Invalid OTP must not grant reset authorization.');
        }
    }

    /** @Then the used OTP cannot start another reset */
    public function usedOtpCannotStartAnotherReset(): void
    {
        $user = $this->requireSubject();
        if (DB::table('password_reset_otps')->where('email', $user->email)->exists()) {
            throw new RuntimeException('A used OTP must be deleted.');
        }
        // Reopening either step after reset must not resurrect the consumed grant.
        $this->visitUrl('/verify-otp');
        $this->assertCurrentPath('/forgot-password');
        $this->visitUrl('/reset-password');
        $this->assertCurrentPath('/forgot-password');
    }

    /** @When I open the password-reset page without OTP verification */
    public function iOpenResetWithoutOtp(): void
    {
        $this->requireSafeEnvironment();
        $this->visitUrl('/reset-password');
    }

    /** @Then the password-reset page redirects to forgot-password */
    public function resetPageRedirectsToForgot(): void
    {
        $this->assertCurrentPath('/forgot-password');
    }

    private function captureOtp(): void
    {
        $sent = Notification::sent($this->requireSubject(), PasswordResetOtpNotification::class);
        $notification = $sent->first();
        if (! $notification instanceof PasswordResetOtpNotification) {
            throw new RuntimeException('Expected a faked OTP notification (none captured).');
        }
        $this->otp = $notification->otp;
    }

    private function submitLogin(string $password): void
    {
        $this->visitUrl('/login');
        $this->submitFirstForm([
            'email' => $this->requireSubject()->email,
            'password' => $password,
        ]);
    }

    private function submitFirstForm(array $fields): void
    {
        $page = $this->session()->getPage();
        foreach ($fields as $name => $value) {
            $page->fillField($name, $value);
        }
        $form = $page->find('css', 'form');
        if ($form === null) {
            throw new RuntimeException('Expected a real Blade form in the current page.');
        }
        $form->submit();
    }

    private function assertCurrentPath(string $expected): void
    {
        $path = parse_url($this->session()->getCurrentUrl(), PHP_URL_PATH);
        if ($path !== $expected) {
            throw new RuntimeException('Expected path '.$expected.', got '.var_export($path, true).'.');
        }
    }

    private function requireSafeEnvironment(): void
    {
        $config = config('database.connections.sqlite.database');
        if (! app()->environment('behat')
            || app()->configurationIsCached()
            || config('database.default') !== 'sqlite'
            || $config !== ':memory:'
            || DB::connection('sqlite')->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || DB::connection('sqlite')->selectOne('PRAGMA database_list')->file !== '') {
            throw new RuntimeException('Auth BDD refused: requires guarded SQLite in-memory environment.');
        }
    }

    private function requireSubject(): User
    {
        return $this->subject ?? throw new RuntimeException('BDD auth user fixture is missing.');
    }

    private function requireOtp(): string
    {
        return $this->otp ?? throw new RuntimeException('BDD OTP fixture is missing.');
    }

    private function uniqueEmail(string $prefix): string
    {
        return 'bdd-'.$prefix.'-'.bin2hex(random_bytes(6)).'@example.test';
    }
}
