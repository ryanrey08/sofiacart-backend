<?php

namespace App\Http\Requests;

use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([PaymentStatus::Completed->value, PaymentStatus::Failed->value, PaymentStatus::Cancelled->value])],
            'reason' => ['required_if:status,failed', 'nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'gateway_reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
