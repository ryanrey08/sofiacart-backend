<?php

namespace App\Http\Requests;

use App\Enums\RefundStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

    public function rules(): array
    {
        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'payment_id' => ['required', 'integer', 'exists:payments,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'reference' => ['required', 'string', 'max:255', 'unique:refunds,reference'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string'],
            'status' => ['required', Rule::enum(RefundStatus::class)],
            'refunded_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
