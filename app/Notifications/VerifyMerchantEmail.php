<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Email verification link for a merchant account, sent to the account's own email address.
 * Sent synchronously (the Docker stack runs no queue worker), so the plain token is never
 * written to the jobs table.
 */
class VerifyMerchantEmail extends Notification
{
    public function __construct(
        public readonly string $token,
        public readonly int $expiresInMinutes,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function verificationUrl(): string
    {
        return config('app.frontend_url').'/verify-email?token='.urlencode($this->token);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Verify your SofiaCart merchant email address')
            ->view(
                ['html' => 'mail.merchant-email-verification', 'text' => 'mail.merchant-email-verification-text'],
                [
                    'name' => $notifiable->name ?? null,
                    'verificationUrl' => $this->verificationUrl(),
                    'expiresIn' => $this->describeExpiry(),
                ],
            );
    }

    protected function describeExpiry(): string
    {
        if ($this->expiresInMinutes % 60 === 0) {
            $hours = intdiv($this->expiresInMinutes, 60);

            return $hours.' '.str('hour')->plural($hours);
        }

        return $this->expiresInMinutes.' '.str('minute')->plural($this->expiresInMinutes);
    }
}
