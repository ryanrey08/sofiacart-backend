<?php

namespace App\Http\Requests;

use App\Enums\PaymentStatus;
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

    public function rules(): array
    {
        $paymentId = $this->route('payment');

        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'reference' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('payments', 'reference')->ignore($paymentId)],
            'gateway' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::enum(PaymentStatus::class)],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'paid_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
