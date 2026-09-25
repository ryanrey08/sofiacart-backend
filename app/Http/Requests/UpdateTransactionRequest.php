<?php

namespace App\Http\Requests;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransactionRequest extends FormRequest
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

    public function rules(): array
    {
        $transactionId = $this->route('transaction');

        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'payment_id' => ['nullable', 'integer', 'exists:payments,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'reference' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('transactions', 'reference')->ignore($transactionId)],
            'type' => ['sometimes', 'required', Rule::enum(TransactionType::class)],
            'status' => ['sometimes', 'required', Rule::enum(TransactionStatus::class)],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'transacted_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
