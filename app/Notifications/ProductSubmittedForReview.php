<?php

namespace App\Notifications;

use App\Models\Product;
use App\Models\ProductChangeRequest;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells admins who can approve products that a merchant submitted a new product, or edits to an
 * approved one. Sent synchronously: the Docker stack runs no queue worker.
 */
class ProductSubmittedForReview extends Notification
{
    public function __construct(
        public Product $product,
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

        if ($this->changeRequest) {
            $mail->line('The approved version stays live until the changes are approved.');
        }

        return $mail->action('Review product', config('app.frontend_url').$this->link());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->changeRequest ? 'product_change_submitted' : 'product_submitted',
            'title' => $this->title(),
            'message' => $this->message(),
            'merchant_id' => $this->product->merchant_id,
            'product_id' => $this->product->id,
            'change_request_id' => $this->changeRequest?->id,
            'fields' => $this->changeRequest?->changedFields(),
            'link' => $this->link(),
        ];
    }

    protected function title(): string
    {
        return $this->changeRequest ? 'Product changes awaiting approval' : 'Product awaiting approval';
    }

    protected function message(): string
    {
        $store = $this->product->merchant?->store_name ?? 'A merchant';

        return $this->changeRequest
            ? "{$store} edited \"{$this->product->name}\": ".NotificationFields::describe($this->changeRequest->changedFields()).'.'
            : "{$store} submitted \"{$this->product->name}\" for approval.";
    }

    protected function link(): string
    {
        return "/admin/products?review=pending&product={$this->product->id}";
    }
}
