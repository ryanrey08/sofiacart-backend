<?php

namespace App\Http\Resources;

use App\Services\MerchantProfileChangeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantChangeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'status' => $this->status?->value,
            'fields' => $this->resource->changedFields(),
            'changes' => (object) ($this->changes ?? []),
            'original' => $this->original,
            // Uploaded replacement documents. The private storage path is never exposed; files are
            // fetched through the authenticated change-request file endpoints.
            'files' => (object) collect($this->files ?? [])
                ->map(fn (array $file) => ['name' => $file['name'], 'mime' => $file['mime'], 'size' => $file['size']])
                ->all(),
            // Current approved value vs requested value, computed from the live record.
            'comparison' => $this->when(
                $this->relationLoaded('merchant') && $this->merchant !== null,
                fn () => app(MerchantProfileChangeService::class)->comparison($this->resource, $this->merchant),
            ),
            'rejection_reason' => $this->rejection_reason,
            'submitted_by' => $this->whenLoaded('submitter', fn () => $this->submitter
                ? ['id' => $this->submitter->id, 'name' => $this->submitter->name]
                : null),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer
                ? ['id' => $this->reviewer->id, 'name' => $this->reviewer->name]
                : null),
            'merchant' => $this->whenLoaded('merchant', fn () => $this->merchant ? [
                'id' => $this->merchant->id,
                'store_name' => $this->merchant->store_name,
                'business_name' => $this->merchant->business_name,
                'store_slug' => $this->merchant->store_slug,
                'status' => $this->merchant->status?->value,
            ] : null),
            'submitted_at' => $this->created_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'withdrawn_at' => $this->withdrawn_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
