<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SanitizesMetadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    use SanitizesMetadata;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'payment_id' => $this->payment_id,
            'order_id' => $this->order_id,
            'reference' => $this->reference,
            'type' => $this->type?->value,
            'status' => $this->status?->value,
            'amount' => $this->amount,
            'description' => $this->description,
            'transacted_at' => $this->transacted_at?->toISOString(),
            'metadata' => $this->sanitizeMetadata($this->metadata),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
