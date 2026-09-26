<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAdminSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*.key' => ['required', 'string', 'max:255'],
            'settings.*.value' => ['nullable'],
            'settings.*.description' => ['nullable', 'string'],
            'settings.*.is_secret' => ['sometimes', 'boolean'],
        ];
    }
}
