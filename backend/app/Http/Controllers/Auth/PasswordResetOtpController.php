<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetOtpNotification;
use Carbon\Carbon;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class PasswordResetOtpController extends Controller
{
    private const OTP_LIFETIME_MINUTES = 5;
    private const MAX_ATTEMPTS = 5;

    public function requestForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendOtp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = Str::lower(trim($data['email']));
        $request->session()->forget(['password_otp_email', 'password_otp_grant']);
        $request->session()->put('password_otp_email', $email);

        // Throttle by email as well as the per-IP HTTP route throttle.
        $rateKey = 'password-otp-send:'.hash('sha256', $email);

        if (! RateLimiter::tooManyAttempts($rateKey, 1)) {
            RateLimiter::hit($rateKey, 60);
            $user = User::query()->where('email', $email)->first();

            if ($user) {
                try {
                    $otp = (string) random_int(100000, 999999);
                    $now = now();

                    // Store only a keyed digest; a 6-digit plain hash is easily bruteforced offline.
                    $digest = $this->digest($email, $otp);
                    $stored = DB::transaction(function () use ($email, $digest, $now): bool {
                        $existing = DB::table('password_reset_otps')
                            ->where('email', $email)->lockForUpdate()->first();

                        if ($existing && Carbon::parse($existing->last_sent_at)->gt($now->copy()->subSeconds(60))) {
                            return false;
                        }

                        $fields = [
                            'code_hash' => $digest,
                            'expires_at' => $now->copy()->addMinutes(self::OTP_LIFETIME_MINUTES),
                            'attempts' => 0,
                            'last_sent_at' => $now,
                            'verified_at' => null,
                            'grant_hash' => null,
                            'grant_expires_at' => null,
                            'updated_at' => $now,
                        ];

                        if ($existing) {
                            DB::table('password_reset_otps')->where('email', $email)->update($fields);
                        } else {
                            DB::table('password_reset_otps')->insert([
                                'email' => $email,
                                'created_at' => $now,
                                ...$fields,
                            ]);
                        }

                        return true;
                    });

                    if ($stored) {
                        $user->notify(new PasswordResetOtpNotification($otp));
                    }
                } catch (Throwable $exception) {
                    // Never log OTPs or SMTP credentials; keep public response generic.
                    report($exception);
                }
            }
        }

        return redirect()->route('password.otp.form')->with(
            'status',
            'Jika email terdaftar dan memenuhi batas permintaan, kode OTP telah dikirim. Periksa inbox atau Spam.'
        );
    }

    public function verifyForm(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('password_otp_email')) {
            return redirect()->route('password.request');
        }

        return view('auth.verify-otp', [
            'email' => $request->session()->get('password_otp_email'),
        ]);
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'otp' => ['required', 'regex:/^\d{6}$/'],
        ]);
        $email = $request->session()->get('password_otp_email');

        if (! is_string($email)) {
            return redirect()->route('password.request');
        }

        $grant = DB::transaction(function () use ($email, $data): ?string {
            $record = DB::table('password_reset_otps')
                ->where('email', $email)->lockForUpdate()->first();

            if (! $record || $record->verified_at !== null
                || $record->attempts >= self::MAX_ATTEMPTS
                || Carbon::parse($record->expires_at)->isPast()) {
                return null;
            }

            $providedDigest = $this->digest($email, $data['otp']);

            if (! hash_equals($record->code_hash, $providedDigest)) {
                DB::table('password_reset_otps')->where('email', $email)->update([
                    'attempts' => $record->attempts + 1,
                    'updated_at' => now(),
                ]);
                return null;
            }

            $token = Str::random(64);
            DB::table('password_reset_otps')->where('email', $email)->update([
                'verified_at' => now(),
                'grant_hash' => $this->digest($email, $token),
                'grant_expires_at' => now()->addMinutes(self::OTP_LIFETIME_MINUTES),
                'updated_at' => now(),
            ]);

            return $token;
        });

        if ($grant === null) {
            return back()->withErrors([
                'otp' => 'Kode OTP salah, kedaluwarsa, sudah digunakan, atau batas percobaan tercapai.',
            ]);
        }

        $request->session()->forget('password_otp_email');
        $request->session()->put('password_otp_grant', [
            'email' => $email,
            'token' => $grant,
        ]);

        return redirect()->route('password.reset.form');
    }

    public function resetForm(Request $request): View|RedirectResponse
    {
        if (! $this->grantValid($request)) {
            $request->session()->forget('password_otp_grant');
            return redirect()->route('password.request')->withErrors([
                'email' => 'Sesi reset tidak valid atau sudah kedaluwarsa. Minta kode OTP baru.',
            ]);
        }

        return view('auth.reset-password');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);
        $grant = $request->session()->get('password_otp_grant');

        if (! is_array($grant) || ! isset($grant['email'], $grant['token'])) {
            return redirect()->route('password.request');
        }

        $user = DB::transaction(function () use ($grant, $data): ?User {
            $record = DB::table('password_reset_otps')
                ->where('email', $grant['email'])->lockForUpdate()->first();

            if (! $this->rowGrantValid($record, $grant['email'], $grant['token'])) {
                return null;
            }

            $user = User::query()->where('email', $grant['email'])->lockForUpdate()->first();
            if (! $user) {
                return null;
            }

            $user->forceFill(['password' => Hash::make($data['password'])]);
            $user->setRememberToken(Str::random(60));
            $user->save();

            // Single use: delete the grant in the same transaction as password update.
            DB::table('password_reset_otps')->where('email', $grant['email'])->delete();

            return $user;
        });

        $request->session()->forget(['password_otp_email', 'password_otp_grant']);

        if (! $user) {
            return redirect()->route('password.request')->withErrors([
                'email' => 'Sesi reset sudah tidak berlaku. Minta kode OTP baru.',
            ]);
        }

        event(new PasswordReset($user));
        try {
            $user->notify(new PasswordChangedNotification());
        } catch (Throwable $exception) {
            report($exception); // Password has already changed; notification failure must not undo it.
        }

        return redirect()->route('login')
            ->with('status', 'Password berhasil diubah. Silakan login dengan password baru.');
    }

    private function grantValid(Request $request): bool
    {
        $grant = $request->session()->get('password_otp_grant');
        if (! is_array($grant) || ! isset($grant['email'], $grant['token'])) {
            return false;
        }
        $row = DB::table('password_reset_otps')->where('email', $grant['email'])->first();

        return $this->rowGrantValid($row, $grant['email'], $grant['token']);
    }

    private function rowGrantValid(?object $row, string $email, string $token): bool
    {
        return $row !== null
            && $row->verified_at !== null
            && $row->grant_hash !== null
            && $row->grant_expires_at !== null
            && Carbon::parse($row->grant_expires_at)->isFuture()
            && hash_equals($row->grant_hash, $this->digest($email, $token));
    }

    private function digest(string $email, string $secret): string
    {
        return hash_hmac('sha256', $email.'|'.$secret, config('app.key'));
    }
}
