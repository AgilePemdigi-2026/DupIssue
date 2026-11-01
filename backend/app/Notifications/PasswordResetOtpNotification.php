<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetOtpNotification extends Notification
{
    public function __construct(public readonly string $otp)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
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
