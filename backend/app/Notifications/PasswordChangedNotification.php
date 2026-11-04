<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Password akun DupIssue telah diubah')
            ->greeting('Pemberitahuan Keamanan DupIssue')
            ->line('Password akun DupIssue Anda berhasil diubah.')
            ->line('Jika Anda tidak melakukan perubahan ini, segera hubungi administrator sistem.');
    }
}
