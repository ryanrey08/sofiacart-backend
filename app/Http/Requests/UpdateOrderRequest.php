<?php

namespace App\Http\Requests;

use App\Enums\OrderPaymentStatus;
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
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'customer_id' => ['sometimes', 'required', 'integer', 'exists:customers,id'],
            'status' => ['sometimes', 'required', Rule::enum(OrderStatus::class)],
            'payment_status' => ['sometimes', 'required', Rule::enum(OrderPaymentStatus::class)],
            'notes' => ['nullable', 'string'],
            'ordered_at' => ['nullable', 'date'],
            'items' => ['sometimes', 'required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.sku' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required_with:items', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'min:0'],
        ];
    }
}
