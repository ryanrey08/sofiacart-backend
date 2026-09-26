<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdminPermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $permissionId = $this->route('permission')?->id ?? $this->route('permission');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/', Rule::unique('admin_permissions', 'name')->ignore($permissionId)],
            'group' => ['sometimes', 'required', 'string', 'max:255'],
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
