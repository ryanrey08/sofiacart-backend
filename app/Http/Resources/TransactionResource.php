<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\IncludesMerchantSummary;
use App\Http\Resources\Concerns\SanitizesMetadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    use IncludesMerchantSummary;
    use SanitizesMetadata;

    /**
     * Payment, refund and order context is included only when loaded. Gateway data is limited
     * to the gateway name and its public transaction id; metadata is redacted.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->merchantSummary(),
            'payment_id' => $this->payment_id,
            'refund_id' => $this->refund_id,
            'order_id' => $this->order_id,
            'reference' => $this->reference,
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'amount' => $this->amount,
            'currency' => $this->relationLoaded('payment') && $this->payment ? $this->payment->currency : config('payments.currency'),
            'payment_method' => $this->whenLoaded('payment', fn () => $this->payment?->method?->value),
            'description' => $this->description,
            'transacted_at' => $this->transacted_at?->toISOString(),
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? [
                'id' => $this->payment->id,
                'reference' => $this->payment->reference,
                'gateway' => $this->payment->gateway,
                'gateway_reference' => $this->payment->gateway_reference,
                'method' => $this->payment->method?->value,
                'status' => $this->payment->status?->value,
                'amount' => $this->payment->amount,
                'paid_at' => $this->payment->paid_at?->toISOString(),
            ] : null),
            'refund' => $this->whenLoaded('refund', fn () => $this->refund ? [
                'id' => $this->refund->id,
                'reference' => $this->refund->reference,
                'status' => $this->refund->status?->value,
                'reason' => $this->refund->reason,
                'amount' => $this->refund->amount,
                'refunded_at' => $this->refund->refunded_at?->toISOString(),
            ] : null),
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status?->value,
                'payment_status' => $this->order->payment_status?->value,
                'total_amount' => $this->order->total_amount,
                'ordered_at' => $this->order->ordered_at?->toISOString(),
            ] : null),
            'customer' => $this->whenLoaded('order', fn () => $this->order?->relationLoaded('customer') && $this->order->customer ? [
                'id' => $this->order->customer->id,
                'name' => $this->order->customer->name,
                'email' => $this->order->customer->email,
                'phone' => $this->order->customer->phone,
            ] : null),
            'metadata' => $this->sanitizeMetadata($this->metadata),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
