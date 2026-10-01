<?php

namespace App\Http\Requests;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')],
            'sku' => ['required', 'string', 'max:255', Rule::unique('products', 'sku')],
            'description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:200'],
            'full_description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::enum(ProductStatus::class)],
            'price' => ['nullable', 'numeric', 'min:0'],
            'regular_price' => ['required_without:price', 'nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'lte:regular_price'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'brand' => ['nullable', 'string', 'max:255'],
            'condition' => ['nullable', 'string', 'max:100'],
            'weight' => ['nullable', 'numeric', 'min:0'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'track_inventory' => ['sometimes', 'boolean'],
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0'],
            'length' => ['nullable', 'numeric', 'min:0'],
            'width' => ['nullable', 'numeric', 'min:0'],
            'height' => ['nullable', 'numeric', 'min:0'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'main_image_index' => ['nullable', 'integer', 'min:0'],
            'variants' => ['nullable', 'array'],
            'variants.*.sku' => ['required', 'string', 'max:255', 'distinct', Rule::unique('product_variants', 'sku')],
            'variants.*.color' => ['nullable', 'string', 'max:100'],
            'variants.*.size' => ['nullable', 'string', 'max:100'],
            'variants.*.attributes' => ['nullable', 'array'],
            'variants.*.price' => ['required', 'numeric', 'min:0'],
            'variants.*.stock' => ['required', 'integer', 'min:0'],
            'variants.*.sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'short_description' => $this->input('short_description', $this->input('description')),
            'full_description' => $this->input('full_description', $this->input('description')),
            'regular_price' => $this->input('regular_price', $this->input('price')),
        ]);

        if (is_string($this->input('variants'))) {
            $this->merge(['variants' => json_decode($this->input('variants'), true) ?: []]);
        }
    }
}
