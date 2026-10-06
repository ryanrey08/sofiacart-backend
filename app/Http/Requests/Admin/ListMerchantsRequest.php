<?php

namespace App\Http\Requests\Admin;

use App\Enums\MerchantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMerchantsRequest extends FormRequest
{
    public const SORTS = ['newest', 'oldest', 'name_asc', 'name_desc', 'orders_desc', 'products_desc', 'collected_desc'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(MerchantStatus::class)],
            'statuses' => ['nullable', 'array'],
            'statuses.*' => [Rule::enum(MerchantStatus::class)],
            'store_category' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'has_pending_changes' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
