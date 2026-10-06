<?php

namespace App\Notifications;

use App\Enums\MerchantChangeRequestStatus;
use App\Models\Merchant;
use App\Models\MerchantChangeRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the merchant that their profile change request was approved or rejected.
 * Sent synchronously: the Docker stack runs no queue worker.
 */
class MerchantProfileChangeReviewed extends Notification
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
        $fields = NotificationFields::describe($this->changeRequest->changedFields());
        $mail = (new MailMessage)
            ->subject($this->title().": {$this->merchant->store_name}")
            ->greeting($this->title());

        if ($this->approved()) {
            $mail->line("Your changes to {$fields} were approved and are now live on your store profile.");
        } else {
            $mail->line("Your changes to {$fields} were not applied. Your approved details are unchanged.")
                ->line('Reason: '.$this->changeRequest->rejection_reason);
        }

        return $mail->action('View store profile', config('app.frontend_url').'/store-profile');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->approved() ? 'merchant_profile_change_approved' : 'merchant_profile_change_rejected',
            'title' => $this->title(),
            'message' => $this->approved()
                ? 'Your store profile changes are now live.'
                : 'Your store profile changes were not applied. Reason: '.$this->changeRequest->rejection_reason,
            'merchant_id' => $this->merchant->id,
            'change_request_id' => $this->changeRequest->id,
            'fields' => $this->changeRequest->changedFields(),
            'reason' => $this->changeRequest->rejection_reason,
            'link' => '/store-profile',
        ];
    }

    protected function approved(): bool
    {
        return $this->changeRequest->status === MerchantChangeRequestStatus::Approved;
    }

    protected function title(): string
    {
        return $this->approved() ? 'Profile changes approved' : 'Profile changes rejected';
    }
}
