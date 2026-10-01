<?php

namespace App\Http\Requests;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('product');

        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($productId)],
            'sku' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('products', 'sku')->ignore($productId)],
            'description' => ['nullable', 'string'],
            'short_description' => ['sometimes', 'required', 'string', 'max:200'],
            'full_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'required', Rule::enum(ProductStatus::class)],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'regular_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'sale_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'lte:regular_price'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:255'],
            'condition' => ['sometimes', 'nullable', 'string', 'max:100'],
            'weight' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:100'],
            'track_inventory' => ['sometimes', 'boolean'],
            'stock_quantity' => ['sometimes', 'required', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0'],
            'length' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'width' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'height' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'main_image_index' => ['nullable', 'integer', 'min:0'],
            'image_ids' => ['sometimes', 'array'],
            'image_ids.*' => ['integer', 'distinct'],
            'main_image_id' => ['nullable', 'integer'],
            'variants' => ['sometimes', 'nullable', 'array'],
            'variants.*.sku' => ['required', 'string', 'max:255', 'distinct'],
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
        if ($this->has('price') && ! $this->has('regular_price')) {
            $this->merge(['regular_price' => $this->input('price')]);
        }

        if ($this->has('sale_price') && ! $this->has('regular_price')) {
            $product = $this->route('product');
            $product = is_object($product) ? $product : Product::find($product);
            $this->merge([
                'regular_price' => $product?->regular_price ?? $product?->price,
            ]);
        }

        if ($this->has('description')) {
            $this->merge([
                'short_description' => $this->input('short_description', $this->input('description')),
                'full_description' => $this->input('full_description', $this->input('description')),
            ]);
        }

        if (is_string($this->input('variants'))) {
            $this->merge(['variants' => json_decode($this->input('variants'), true) ?: []]);
        }
    }
}
