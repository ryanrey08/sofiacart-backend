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
            'product_variant_id' => $this->product_variant_id,
            'user_id' => $this->user_id,
            'type' => $this->type?->value,
            'reason' => $this->reason,
            'quantity_change' => $this->quantity_change,
            'previous_stock' => $this->resulting_stock - $this->quantity_change,
            'resulting_stock' => $this->resulting_stock,
            'reference_type' => $this->reference_type,
            'reference_number' => $this->reference_number,
            'supplier' => $this->supplier,
            'reference_date' => $this->reference_date?->toDateString(),
            'notes' => $this->notes,
            'product' => ProductResource::make($this->whenLoaded('product')),
            'variant' => $this->whenLoaded('variant', fn () => $this->variant ? [
                'id' => $this->variant->id,
                'sku' => $this->variant->sku,
                'color' => $this->variant->color,
                'size' => $this->variant->size,
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
