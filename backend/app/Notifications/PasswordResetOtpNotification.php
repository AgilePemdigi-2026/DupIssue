<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetOtpNotification extends Notification implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $otp)
    {
        // Never put OTP plaintext into unencrypted queue payloads.
        $this->onConnection('database');
        $this->afterCommit();
    }

    // Worker skips an OTP that has expired, been used, or been superseded.
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $email = Str::lower(trim((string) $notifiable->email));
        $digest = hash_hmac('sha256', $email.'|'.$this->otp, config('app.key'));

        return DB::table('password_reset_otps')
            ->where('email', $email)
            ->where('code_hash', $digest)
            ->where('expires_at', '>', now())
            ->whereNull('verified_at')
            ->exists();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Fail closed even if mail transport changes after the job is queued.
        if (! app()->runningUnitTests() && in_array(config('mail.default'), ['log', 'array'], true)) {
            throw new \RuntimeException('OTP mailer must not be log/array. Configure SMTP.');
        }

        return (new MailMessage)
            ->subject('Kode OTP Reset Password DupIssue')
            ->greeting('Reset Password DupIssue')
            ->line('Kami menerima permintaan reset password untuk akun Anda.')
            ->line('Kode OTP Anda: **'.$this->otp.'**')
            ->line('Kode ini hanya berlaku selama 5 menit dan maksimal 5 kali percobaan.')
            ->line('Masukkan kode pada halaman DupIssue yang sudah Anda buka.')
            ->line('Jangan bagikan kode kepada siapa pun. Abaikan email ini jika bukan Anda yang meminta reset.');
    }
}
