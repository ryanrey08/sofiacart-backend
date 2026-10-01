<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'order_id' => $this->order_id,
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
