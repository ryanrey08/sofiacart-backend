<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInventoryItemsRequest extends FormRequest
{
    public const SORTS = [
        'name_asc', 'name_desc', 'sku_asc', 'sku_desc',
        'stock_asc', 'stock_desc', 'available_asc', 'available_desc',
        'reserved_asc', 'reserved_desc', 'updated_asc', 'updated_desc',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'merchant_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'stock_status' => ['nullable', Rule::in(['active', 'low_stock', 'out_of_stock'])],
            'type' => ['nullable', Rule::in(['product', 'variant'])],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
