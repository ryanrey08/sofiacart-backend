<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentRequest extends FormRequest
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
     * Details only; status changes go through PATCH /payments/{payment}/status so they follow
     * the payment lifecycle.
     */
    public function rules(): array
    {
        $paymentId = $this->route('payment');

        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'reference' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('payments', 'reference')->ignore($paymentId)],
            'gateway_reference' => ['nullable', 'string', 'max:255'],
            'gateway' => ['sometimes', 'required', 'string', 'max:255'],
            'method' => ['sometimes', 'nullable', Rule::enum(PaymentMethod::class)],
            'status' => ['prohibited'],
            'amount' => ['sometimes', 'required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:500'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return ['status.prohibited' => 'Use PATCH /payments/{payment}/status to change a payment status.'];
    }
}
