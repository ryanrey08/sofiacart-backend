<?php

namespace App\Http\Resources;

use App\Services\ProductApprovalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductChangeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'status' => $this->status?->value,
            'fields' => $this->resource->changedFields(),
            'changes' => (object) ($this->resource->getAttribute('changes') ?? []),
            'original' => (object) ($this->original ?? []),
            // Replacement gallery (public-disk paths, not linked anywhere on the storefront until approved).
            'images' => $this->images,
            // Current live value vs requested value; only while pending (afterwards it describes history).
            'comparison' => $this->when(
                $this->resource->isPending() && $this->relationLoaded('product') && $this->product !== null,
                fn () => app(ProductApprovalService::class)->comparison($this->resource, $this->product),
            ),
            'rejection_reason' => $this->rejection_reason,
            'submitted_by' => $this->whenLoaded('submitter', fn () => $this->submitter
                ? ['id' => $this->submitter->id, 'name' => $this->submitter->name]
                : null),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer
                ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name]
                : null),
            'submitted_at' => $this->created_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'withdrawn_at' => $this->withdrawn_at?->toISOString(),
        ];
    }
}
