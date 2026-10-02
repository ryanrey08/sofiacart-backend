<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListPaymentsRequest extends FormRequest
{
    public const SORTS = ['newest', 'oldest', 'amount_desc', 'amount_asc', 'paid_at_desc', 'paid_at_asc'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'merchant_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'gateway' => ['nullable', 'string', 'max:255'],
            'order_id' => ['nullable', 'integer'],
            'customer_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
