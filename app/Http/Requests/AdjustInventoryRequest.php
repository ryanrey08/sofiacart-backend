<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdjustInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'reason' => ['required', 'string', 'max:255'],
            'quantity_change' => ['required', 'integer', 'not_in:0'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
