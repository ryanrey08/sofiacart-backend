<?php

namespace App\Notifications;

use App\Models\Merchant;
use App\Models\MerchantChangeRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells admins who can review merchant changes that a merchant submitted profile edits.
 * Sent synchronously: the Docker stack runs no queue worker.
 */
class MerchantProfileChangeSubmitted extends Notification
{
    public function __construct(
        public MerchantChangeRequest $changeRequest,
        public Merchant $merchant,
    ) {}

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
            ->subject("Profile changes awaiting approval: {$this->merchant->store_name}")
            ->greeting('Profile changes awaiting approval')
            ->line("{$this->merchant->store_name} submitted changes to: ".NotificationFields::describe($this->changeRequest->changedFields()).'.')
            ->line("The merchant's live details stay unchanged until the request is approved.")
            ->action('Review changes', config('app.frontend_url').$this->link());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $count = count($this->changeRequest->changedFields());

        return [
            'kind' => 'merchant_profile_change_submitted',
            'title' => 'Profile changes awaiting approval',
            'message' => "{$this->merchant->store_name} requested changes to {$count} ".($count === 1 ? 'field' : 'fields').'.',
            'merchant_id' => $this->merchant->id,
            'change_request_id' => $this->changeRequest->id,
            'fields' => $this->changeRequest->changedFields(),
            'link' => $this->link(),
        ];
    }

    protected function link(): string
    {
        return "/admin/merchants/{$this->merchant->id}";
    }
}
