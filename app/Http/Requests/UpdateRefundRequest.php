<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRefundRequest extends FormRequest
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
     * Details only; status changes go through PATCH /refunds/{refund}/status.
     */
    public function rules(): array
    {
        $refundId = $this->route('refund');

        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'payment_id' => ['sometimes', 'required', 'integer', 'exists:payments,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'reference' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('refunds', 'reference')->ignore($refundId)],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'status' => ['prohibited'],
            'notes' => ['nullable', 'string', 'max:500'],
            'refunded_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return ['status.prohibited' => 'Use PATCH /refunds/{refund}/status to change a refund status.'];
    }
}
