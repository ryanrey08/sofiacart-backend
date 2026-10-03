<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\IncludesMerchantSummary;
use App\Http\Resources\Concerns\SanitizesMetadata;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    use IncludesMerchantSummary;
    use SanitizesMetadata;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->merchantSummary(),
            'order_id' => $this->order_id,
            'reference' => $this->reference,
            'gateway_reference' => $this->gateway_reference,
            'gateway' => $this->gateway,
            'method' => $this->method?->value,
            'status' => $this->status?->value,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paid_at' => $this->paid_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'failure_reason' => $this->failure_reason,
            'notes' => $this->notes,
            'attachments' => collect($this->attachments ?? [])->map(fn (array $file, int $index) => [
                'index' => $index,
                'name' => $file['name'] ?? null,
                'mime' => $file['mime'] ?? null,
                'size' => $file['size'] ?? null,
            ])->values(),
            'verified_by' => $this->whenLoaded('verifier', fn () => $this->verifier ? [
                'id' => $this->verifier->id,
                'name' => $this->verifier->name,
            ] : null),
            'order' => OrderResource::make($this->whenLoaded('order')),
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'metadata' => $this->sanitizeMetadata($this->metadata),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
