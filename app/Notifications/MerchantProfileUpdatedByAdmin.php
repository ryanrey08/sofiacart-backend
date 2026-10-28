<?php

namespace App\Notifications;

use App\Models\Merchant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the merchant that a SofiaCart admin changed their store details directly.
 * Sent synchronously, like the other profile notifications.
 */
class MerchantProfileUpdatedByAdmin extends Notification
{
    /**
     * @param  array<int, string>  $fields
     */
    public function __construct(
        public Merchant $merchant,
        public array $fields,
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
            ->subject("Store details updated: {$this->merchant->store_name}")
            ->greeting('Store details updated')
            ->line('SofiaCart updated your '.NotificationFields::describe($this->fields).'. The changes are already live.')
            ->line('If something looks wrong, contact SofiaCart support.')
            ->action('View store profile', config('app.frontend_url').'/store-profile');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'merchant_profile_updated_by_admin',
            'title' => 'Store details updated',
            'message' => 'SofiaCart updated your '.NotificationFields::describe($this->fields).'.',
            'merchant_id' => $this->merchant->id,
            'fields' => $this->fields,
            'link' => '/store-profile',
        ];
    }
}
