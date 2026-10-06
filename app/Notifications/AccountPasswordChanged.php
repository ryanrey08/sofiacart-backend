<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Security notice sent to the account owner after their password changes.
 * Sent synchronously: the Docker stack runs no queue worker.
 */
class AccountPasswordChanged extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return filled($notifiable->email ?? null) ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your SofiaCart password was changed')
            ->greeting('Password changed')
            ->line('The password for your SofiaCart merchant account was just changed, and your other signed-in sessions were signed out.')
            ->line('If you did not make this change, reset your password and contact SofiaCart support immediately.')
            ->action('Review your account', config('app.frontend_url').'/account');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'account_password_changed',
            'title' => 'Password changed',
            'message' => "Your account password was changed. If this wasn't you, contact SofiaCart support immediately.",
            'link' => '/account',
        ];
    }
}
