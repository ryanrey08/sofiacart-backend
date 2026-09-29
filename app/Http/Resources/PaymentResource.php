<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'order_id' => $this->order_id,
            'reference' => $this->reference,
            'gateway' => $this->gateway,
            'status' => $this->status?->value,
            'amount' => $this->amount,
            'paid_at' => $this->paid_at?->toISOString(),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
