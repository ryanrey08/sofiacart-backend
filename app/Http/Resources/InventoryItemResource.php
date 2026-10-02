<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Wraps a row from InventoryService::inventoryItemsQuery(): one stock pool (a product's base
 * stock or one variant).
 */
class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $images = is_string($this->images) ? json_decode($this->images, true) : $this->images;
        $costPrice = $this->cost_price !== null ? (float) $this->cost_price : null;
        $onHand = (int) $this->on_hand;

        return [
            'id' => $this->item_type.':'.($this->product_variant_id ?? $this->product_id),
            'item_type' => $this->item_type,
            'product_id' => (int) $this->product_id,
            'product_variant_id' => $this->product_variant_id !== null ? (int) $this->product_variant_id : null,
            'merchant_id' => (int) $this->merchant_id,
            'name' => $this->name,
            'sku' => $this->sku,
            'brand' => $this->brand,
            'product_status' => $this->product_status,
            'variant' => $this->item_type === 'variant' ? [
                'color' => $this->color,
                'size' => $this->size,
            ] : null,
            'category' => $this->category_id !== null ? [
                'id' => (int) $this->category_id,
                'name' => $this->category_name,
            ] : null,
            'image' => is_array($images) ? ($images[0] ?? null) : null,
            'price' => $this->decimal($this->price),
            'cost_price' => $this->decimal($costPrice),
            'track_inventory' => (bool) $this->track_inventory,
            'low_stock_threshold' => (int) $this->low_stock_threshold,
            'on_hand' => $onHand,
            'reserved' => (int) $this->reserved,
            'available' => (int) $this->available,
            'stock_status' => $this->stock_status,
            'inventory_value' => $costPrice !== null ? $this->decimal($costPrice * $onHand) : null,
            'updated_at' => $this->updated_at ? Carbon::parse($this->updated_at)->toISOString() : null,
        ];
    }

    protected function decimal(mixed $value): ?string
    {
        return $value !== null ? number_format((float) $value, 2, '.', '') : null;
    }
}
