<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Approve or reject a product submission, or a pending edit to an approved product. Same
 * convention as ReviewMerchantChangeRequest: a rejection must tell the merchant why.
 */
class ReviewProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('review', Product::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['approved', 'rejected'])],
            'reason' => ['nullable', 'string', 'max:1000', Rule::requiredIf($this->input('status') === 'rejected')],
            // The submission the reviewer looked at (product `approval.submitted_at`); a newer
            // resubmission returns 409 instead of being approved unseen.
            'submitted_at' => ['nullable', 'string', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required when rejecting a product.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
