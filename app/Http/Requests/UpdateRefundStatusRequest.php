<?php

namespace App\Http\Requests;

use App\Enums\RefundStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRefundStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(array_column(array_filter(
                RefundStatus::cases(),
                fn (RefundStatus $status) => $status !== RefundStatus::Pending,
            ), 'value'))],
            'notes' => ['nullable', 'string', 'max:500'],
            'payout_reference' => ['nullable', 'string', 'max:255'],
            'failure_reason' => ['required_if:status,failed', 'nullable', 'string', 'max:255'],
            'refunded_at' => ['nullable', 'date', 'before_or_equal:now'],
            'cancel_order' => ['sometimes', 'boolean'],
        ];
    }
}
