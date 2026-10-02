<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\SanitizesMetadata;
use App\Models\ReturnRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
{
    use SanitizesMetadata;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'payment_id' => $this->payment_id,
            'order_id' => $this->order_id,
            'return_request_id' => $this->return_request_id,
            'reference' => $this->reference,
            'payout_reference' => $this->payout_reference,
            'amount' => $this->amount,
            'currency' => $this->relationLoaded('payment') && $this->payment ? $this->payment->currency : config('payments.currency'),
            'reason' => $this->reason,
            'notes' => $this->notes,
            'failure_reason' => $this->failure_reason,
            'status' => $this->status?->value,
            'cancel_order' => (bool) $this->cancel_order,
            'refunded_at' => $this->refunded_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester ? [
                'id' => $this->requester->id,
                'name' => $this->requester->name,
            ] : null),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id' => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'order_item_id' => $item->order_item_id,
                'product_name' => $item->orderItem?->product_name,
                'sku' => $item->orderItem?->sku,
                'unit_price' => $item->orderItem?->unit_price,
                'quantity' => $item->quantity,
                'amount' => $item->amount,
            ])->values()),
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? [
                'id' => $this->payment->id,
                'reference' => $this->payment->reference,
                'gateway' => $this->payment->gateway,
                'method' => $this->payment->method?->value,
                'amount' => $this->payment->amount,
                'currency' => $this->payment->currency,
                'status' => $this->payment->status?->value,
            ] : null),
            'order' => OrderResource::make($this->whenLoaded('order')),
            'return_request' => $this->when(
                $this->relationLoaded('returnRequest') || $this->relationLoaded('processedReturn'),
                fn () => $this->returnRequestSummary($this->returnRequest ?? $this->processedReturn),
            ),
            'transactions' => TransactionResource::collection($this->whenLoaded('transactions')),
            'history' => $this->whenLoaded('histories', fn () => $this->histories->map(fn ($entry) => [
                'from_status' => $entry->from_status?->value,
                'to_status' => $entry->to_status?->value,
                'notes' => $entry->notes,
                'user' => $entry->user ? ['id' => $entry->user->id, 'name' => $entry->user->name] : null,
                'created_at' => $entry->created_at?->toISOString(),
            ])->values()),
            'metadata' => $this->sanitizeMetadata($this->metadata),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function returnRequestSummary(?ReturnRequest $return): ?array
    {
        return $return ? [
            'id' => $return->id,
            'status' => $return->status,
            'reason' => $return->reason,
            'notes' => $return->notes,
            'evidence' => collect($return->evidence ?? [])->map(fn (array $file, int $index) => [
                'index' => $index,
                'name' => $file['name'] ?? null,
                'mime' => $file['mime'] ?? null,
            ])->values(),
        ] : null;
    }
}
