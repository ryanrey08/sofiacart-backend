<?php

namespace App\Http\Requests;

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTransactionsRequest extends FormRequest
{
    public const SORTS = ['newest', 'oldest', 'amount_desc', 'amount_asc'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'merchant_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(TransactionType::class)],
            'status' => ['nullable', Rule::enum(TransactionStatus::class)],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            'customer_id' => ['nullable', 'integer'],
            'order_id' => ['nullable', 'integer'],
            'payment_id' => ['nullable', 'integer'],
            'refund_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
