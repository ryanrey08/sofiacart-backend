<?php

namespace App\Http\Requests;

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTransactionRequest extends FormRequest
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
        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'payment_id' => ['nullable', 'integer', 'exists:payments,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'reference' => ['required', 'string', 'max:255', 'unique:transactions,reference'],
            'type' => ['required', Rule::enum(TransactionType::class)],
            'status' => ['required', Rule::enum(TransactionStatus::class)],
            'amount' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'transacted_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
