<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'product_id' => $this->product_id,
            'user_id' => $this->user_id,
            'reason' => $this->reason,
            'quantity_change' => $this->quantity_change,
            'resulting_stock' => $this->resulting_stock,
            'notes' => $this->notes,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
