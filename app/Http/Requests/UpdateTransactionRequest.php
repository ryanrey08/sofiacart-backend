<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
{
    public const IMMUTABLE_FIELDS = [
        'merchant_id', 'payment_id', 'refund_id', 'order_id', 'reference', 'source_key',
        'type', 'status', 'amount', 'description', 'transacted_at',
    ];

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
     * Ledger rows are immutable: only `metadata` (e.g. reconciliation notes) may be annotated.
     * Status, amount and links are maintained by the payment and refund services.
     */
    public function rules(): array
    {
        return [
            'metadata' => ['required', 'array'],
            ...array_fill_keys(self::IMMUTABLE_FIELDS, ['prohibited']),
        ];
    }

    public function messages(): array
    {
        return array_fill_keys(
            array_map(fn (string $field) => "{$field}.prohibited", self::IMMUTABLE_FIELDS),
            'Transactions are immutable; only metadata can be updated.',
        );
    }
}
