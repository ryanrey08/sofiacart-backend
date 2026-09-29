<?php

namespace App\Http\Requests;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'payment_status' => ['nullable', Rule::enum(OrderPaymentStatus::class)],
            'notes' => ['nullable', 'string'],
            'ordered_at' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.sku' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }
}
