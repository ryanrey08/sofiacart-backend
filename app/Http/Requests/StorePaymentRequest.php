<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
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
     * `status` defaults to pending (initialise a payment awaiting confirmation); "completed"
     * records money already received and is attributed to the authenticated user.
     */
    public function rules(): array
    {
        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'reference' => ['nullable', 'string', 'max:255', 'unique:payments,reference'],
            'gateway_reference' => ['nullable', 'string', 'max:255'],
            'gateway' => ['required_without:method', 'nullable', 'string', 'max:255'],
            'method' => ['required_without:gateway', 'nullable', Rule::enum(PaymentMethod::class)],
            'status' => ['sometimes', Rule::in([PaymentStatus::Pending->value, PaymentStatus::Completed->value, PaymentStatus::Failed->value])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', Rule::in([config('payments.currency')])],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'notes' => ['nullable', 'string', 'max:500'],
            'failure_reason' => ['nullable', 'string', 'max:255'],
            'attachments' => ['sometimes', 'array', 'max:'.config('payments.attachments.max_files')],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:'.config('payments.attachments.max_kilobytes')],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
