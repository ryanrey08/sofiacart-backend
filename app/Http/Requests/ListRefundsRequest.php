<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\RefundStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRefundsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'merchant_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(RefundStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
            'payment_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'return_request_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'order_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'amount_desc', 'amount_asc'])],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
