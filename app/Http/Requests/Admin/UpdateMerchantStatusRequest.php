<?php

namespace App\Http\Requests\Admin;

use App\Enums\MerchantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(MerchantStatus::class)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
