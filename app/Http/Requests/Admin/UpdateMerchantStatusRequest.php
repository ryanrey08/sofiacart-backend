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

    /**
     * Rejections and information requests must tell the merchant why, so the reason is
     * required for those decisions and recorded in the audit log.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(MerchantStatus::class)],
            'reason' => [
                'nullable',
                'string',
                'max:1000',
                Rule::requiredIf(in_array($this->input('status'), [
                    MerchantStatus::Rejected->value,
                    MerchantStatus::InformationRequested->value,
                ], true)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required when rejecting a merchant or requesting more information.',
        ];
    }
}
