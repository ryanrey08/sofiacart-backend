<?php

namespace App\Http\Requests;

use App\Enums\RefundStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->metadata)) {
            $decoded = json_decode($this->metadata, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge(['metadata' => $decoded]);
            }
        }
    }

    /**
     * The refund amount comes from exactly one source, always priced/validated server-side:
     * `return_request_id` (an approved return), `items` (order items and quantities),
     * `full_refund` (the payment's whole remaining balance) or a legacy explicit `amount`
     * (capped at the refundable balance).
     */
    public function rules(): array
    {
        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'order_id' => ['required_without_all:payment_id,return_request_id', 'nullable', 'integer', 'exists:orders,id'],
            'payment_id' => ['nullable', 'integer', 'exists:payments,id'],
            'customer_id' => ['nullable', 'integer'],
            'return_request_id' => ['nullable', 'integer', 'exists:return_requests,id'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'full_refund' => ['sometimes', 'boolean'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'reference' => ['nullable', 'string', 'max:255', 'unique:refunds,reference'],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', Rule::in([RefundStatus::Pending->value, RefundStatus::Processed->value])],
            'payout_reference' => ['nullable', 'string', 'max:255'],
            'refunded_at' => ['nullable', 'date', 'before_or_equal:now'],
            'cancel_order' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $sources = array_filter([
                    'return_request_id' => $this->filled('return_request_id'),
                    'items' => $this->filled('items'),
                    'full_refund' => $this->boolean('full_refund'),
                    'amount' => $this->filled('amount'),
                ]);

                if (count($sources) !== 1) {
                    $validator->errors()->add(
                        'amount',
                        'Provide exactly one of return_request_id, items, full_refund or amount.',
                    );
                }

                $key = $this->header('Idempotency-Key');
                if ($key !== null && (strlen($key) > 100 || $key === '')) {
                    $validator->errors()->add('idempotency_key', 'The Idempotency-Key header must be 1 to 100 characters.');
                }
            },
        ];
    }
}
