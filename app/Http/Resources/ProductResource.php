<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'category_id' => $this->category_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'full_description' => $this->full_description,
            'status' => $this->status?->value,
            'price' => $this->price,
            'regular_price' => $this->regular_price ?? $this->price,
            'sale_price' => $this->sale_price,
            'cost_price' => $this->cost_price,
            'brand' => $this->brand,
            'condition' => $this->condition,
            'weight' => $this->weight,
            'tags' => $this->tags ?? [],
            'track_inventory' => $this->track_inventory,
            'stock_quantity' => $this->stock_quantity,
            'low_stock_threshold' => $this->low_stock_threshold,
            'stock_status' => $this->stock_status,
            'dimensions' => [
                'length' => $this->length,
                'width' => $this->width,
                'height' => $this->height,
            ],
            'images' => $this->images,
            'image_items' => $this->whenLoaded('imageRecords', fn () => $this->imageRecords->map(fn ($image) => [
                'id' => $image->id,
                'path' => $image->path,
                'is_main' => $image->is_main,
                'sort_order' => $image->sort_order,
            ])->values()),
            'variants' => $this->whenLoaded('variants', fn () => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'color' => $variant->color,
                'size' => $variant->size,
                'attributes' => $variant->attributes ?? [],
                'price' => $variant->price,
                'stock' => $variant->stock,
                'sort_order' => $variant->sort_order,
            ])->values()),
            'category' => CategoryResource::make($this->whenLoaded('category')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
