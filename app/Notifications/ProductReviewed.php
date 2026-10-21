<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductChangeRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the merchant that their product (or their edit to an approved product) was approved or
 * rejected. Sent synchronously: the Docker stack runs no queue worker.
 */
class ProductReviewed extends Notification
{
    public function __construct(
        public Product $product,
        public bool $approved,
        public ?ProductChangeRequest $changeRequest = null,
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
        $mail = (new MailMessage)
            ->subject($this->title().": {$this->product->name}")
            ->greeting($this->title())
            ->line($this->message());

        if (! $this->approved) {
            $mail->line('Reason: '.$this->reason());
        }

        return $mail->action('View product', config('app.frontend_url').$this->link());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => ($this->changeRequest ? 'product_change_' : 'product_').($this->approved ? 'approved' : 'rejected'),
            'title' => $this->title(),
            'message' => $this->approved ? $this->message() : $this->message().' Reason: '.$this->reason(),
            'merchant_id' => $this->product->merchant_id,
            'product_id' => $this->product->id,
            'change_request_id' => $this->changeRequest?->id,
            'reason' => $this->approved ? null : $this->reason(),
            'link' => $this->link(),
        ];
    }

    protected function title(): string
    {
        $subject = $this->changeRequest ? 'Product changes' : 'Product';

        return $subject.($this->approved ? ' approved' : ' rejected');
    }

    protected function message(): string
    {
        if ($this->changeRequest) {
            return $this->approved
                ? "Your changes to \"{$this->product->name}\" were approved and are now live."
                : "Your changes to \"{$this->product->name}\" were not applied. The approved version is unchanged.";
        }

        return $this->approved
            ? "\"{$this->product->name}\" was approved and can now appear on the SofiaCart storefront."
            : "\"{$this->product->name}\" was not approved. Edit it and resubmit it for approval.";
    }

    protected function reason(): ?string
    {
        return $this->changeRequest?->rejection_reason ?? $this->product->rejection_reason;
    }

    protected function link(): string
    {
        return "/sales/products?saved={$this->product->id}";
    }
}
