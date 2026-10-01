<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'status' => ['prohibited'],
            'payment_status' => ['prohibited'],
            'notes' => ['nullable', 'string'],
            'ordered_at' => ['prohibited'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.product_name' => ['nullable', 'string', 'max:255'],
            'items.*.sku' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required_without:items.*.product_id', 'numeric', 'min:0'],
            'subtotal' => ['prohibited'],
            'discount_amount' => ['prohibited'],
            'shipping_amount' => ['prohibited'],
            'shipping_address' => ['prohibited'],
        ];
    }
}
