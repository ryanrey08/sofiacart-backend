<?php

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'status' => ['sometimes', 'required', Rule::enum(OrderStatus::class)],
            'payment_status' => ['prohibited'],
            'notes' => ['nullable', 'string'],
            'ordered_at' => ['prohibited'],
            'items' => ['prohibited'],
        ];
    }
}
