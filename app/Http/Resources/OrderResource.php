<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\IncludesMerchantSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    use IncludesMerchantSummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->merchantSummary(),
            'customer_id' => $this->customer_id,
            'order_number' => $this->order_number,
            'status' => $this->status?->value,
            'payment_status' => $this->payment_status?->value,
            'total_amount' => $this->total_amount,
            'subtotal' => $this->subtotal ?? $this->total_amount,
            'discount_amount' => $this->discount_amount,
            'shipping_amount' => $this->shipping_amount,
            'shipping_address' => $this->shipping_address,
            'notes' => $this->notes,
            'ordered_at' => $this->ordered_at?->toISOString(),
            'inventory_restored' => (bool) $this->inventory_restored,
            'customer' => CustomerResource::make($this->whenLoaded('customer')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'return_requests' => ReturnRequestResource::collection($this->whenLoaded('returnRequests')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
