<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\IncludesMerchantSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnRequestResource extends JsonResource
{
    use IncludesMerchantSummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->merchantSummary(),
            'order_id' => $this->order_id,
            'order' => $this->whenLoaded('order', fn () => $this->order ? [
                'id' => $this->order->id,
                'order_number' => $this->order->order_number,
                'status' => $this->order->status?->value,
                'payment_status' => $this->order->payment_status?->value,
                'total_amount' => $this->order->total_amount,
            ] : null),
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ] : null),
            'customer_id' => $this->customer_id,
            'refund_id' => $this->refund_id,
            'status' => $this->status,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'amount' => $this->amount,
            'items' => $this->whenLoaded('items'),
            'evidence' => collect($this->evidence ?? [])->map(fn ($file, $index) => [
                'name' => $file['name'],
                'mime' => $file['mime'],
                'url' => url("/api/v1/return-requests/{$this->id}/evidence/{$index}"),
            ])->all(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
