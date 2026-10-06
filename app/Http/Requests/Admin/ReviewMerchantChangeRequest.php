<?php

namespace App\Http\Requests\Admin;

use App\Enums\MerchantChangeRequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ReviewMerchantChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review', $this->route('changeRequest'));
    }

    /**
     * Same convention as UpdateMerchantStatusRequest: a rejection must tell the merchant why.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                MerchantChangeRequestStatus::Approved->value,
                MerchantChangeRequestStatus::Rejected->value,
            ])],
            'reason' => [
                'nullable',
                'string',
                'max:1000',
                Rule::requiredIf($this->input('status') === MerchantChangeRequestStatus::Rejected->value),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required when rejecting profile changes.',
        ];
    }
}
