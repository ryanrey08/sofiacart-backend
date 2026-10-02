<?php

namespace App\Http\Requests;

use App\Enums\InventoryMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInventoryLogsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'merchant_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'integer'],
            'product_variant_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::enum(InventoryMovementType::class)],
            'reason' => ['nullable', 'string', 'max:255'],
            'reference_type' => ['nullable', 'string', 'max:64'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'sort' => ['nullable', Rule::in(['newest', 'oldest'])],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
