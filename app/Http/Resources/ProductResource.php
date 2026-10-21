<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\IncludesMerchantSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    use IncludesMerchantSummary;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->merchantSummary(),
            'category_id' => $this->category_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'full_description' => $this->full_description,
            'status' => $this->status?->value,
            // Approval state. Only an admin decision sets approved_at/reviewed_*/rejection_reason.
            'approval' => [
                'is_approved' => $this->approved_at !== null,
                'submitted_at' => $this->submitted_at?->toISOString(),
                'approved_at' => $this->approved_at?->toISOString(),
                'reviewed_at' => $this->reviewed_at?->toISOString(),
                'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer
                    ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name]
                    : null),
                'rejection_reason' => $this->rejection_reason,
            ],
            // Customer-facing outcome: published, out_of_stock, hidden, store_unavailable, or the
            // pre-approval state (draft, pending_approval, rejected, archived).
            'listing_status' => $this->resource->listingStatus(),
            'pending_change_request' => $this->whenLoaded('pendingChangeRequest', fn () => $this->pendingChangeRequest
                ? ProductChangeRequestResource::make($this->pendingChangeRequest->setRelation('product', $this->resource))
                : null),
            // The most recent edit request in any state, so a rejection reason stays visible to the merchant.
            'latest_change_request' => $this->whenLoaded('latestChangeRequest', fn () => $this->latestChangeRequest
                ? ProductChangeRequestResource::make($this->latestChangeRequest)
                : null),
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
