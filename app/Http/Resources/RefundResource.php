<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'payment_id' => $this->payment_id,
            'order_id' => $this->order_id,
            'reference' => $this->reference,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'status' => $this->status?->value,
            'refunded_at' => $this->refunded_at?->toISOString(),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
