<?php

namespace App\Http\Requests\Admin;

use App\Enums\MerchantChangeRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListMerchantChangeRequestsRequest extends FormRequest
{
    public const SORTS = ['newest', 'oldest'];

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
            'status' => ['nullable', Rule::enum(MerchantChangeRequestStatus::class)],
            'merchant_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
