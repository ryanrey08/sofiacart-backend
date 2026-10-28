<?php

namespace App\Http\Requests\Admin;

use App\Models\Merchant;
use App\Services\MerchantProfileChangeService;
use App\Services\RegistrationOptionsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An admin's direct edit of a merchant (applied without approval). Uses the merchant change rules
 * and dropdown lists, plus the compliance fields only an admin may change. Access is enforced by
 * the route's merchants.manage permission.
 */
class UpdateMerchantProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $phoneRegex = '/^(?:\\+63|0)9\\d{9}$/';
        $imageMax = 'max:'.config('uploads.image_max_kilobytes');

        $rules = [
            'business_name' => ['sometimes', 'required', 'string', 'max:255'],
            'business_type' => ['sometimes', 'required', 'string', 'max:255'],
            'business_category' => ['sometimes', 'required', 'string', 'max:255'],
            'business_address' => ['sometimes', 'required', 'string', 'max:1000'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'province' => ['sometimes', 'required', 'string', 'max:255'],
            'zip_code' => ['sometimes', 'required', 'string', 'max:10'],
            'store_name' => ['sometimes', 'required', 'string', 'max:255'],
            'store_category' => ['sometimes', 'required', 'string', 'max:255'],
            'store_description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'store_address' => ['sometimes', 'required', 'string', 'max:1000'],
            'contact_phone' => ['sometimes', 'required', 'regex:'.$phoneRegex],
            'contact_email' => ['sometimes', 'required', 'email', 'max:255'],
            'social_links' => ['sometimes', 'nullable', 'array'],
            'social_links.*' => ['nullable', 'url', 'max:255'],
            'owner_name' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_position' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_email' => ['sometimes', 'required', 'email', 'max:255'],
            'owner_phone' => ['sometimes', 'required', 'regex:'.$phoneRegex],
            'tin' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('merchants', 'tin')->ignore($this->merchant()->id)],
            'business_permit_number' => ['sometimes', 'required', 'string', 'max:255'],
            'owner_birth_date' => ['sometimes', 'required', 'date', 'before:today'],
            'government_id_type' => ['sometimes', 'required', 'string', 'max:255'],
            'government_id_number' => ['sometimes', 'required', 'string', 'max:255'],
            // An admin may record an ID that has since expired, so no "after today" rule here.
            'government_id_expiry_date' => ['sometimes', 'required', 'date'],
            'store_logo' => ['sometimes', 'image', 'mimes:jpg,jpeg,png', $imageMax],
            'store_banner' => ['sometimes', 'image', 'mimes:jpg,jpeg,png', $imageMax],
            'business_permit' => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png', $imageMax],
            'government_id' => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png', $imageMax],
        ];

        $rules = [...$rules, ...app(RegistrationOptionsService::class)->rules(partial: true)];

        foreach (array_diff(MerchantProfileChangeService::ADMIN_CONTROLLED_FIELDS, MerchantProfileChangeService::ADMIN_EDITABLE_FIELDS) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * Unchanged dropdown and address values are dropped before validation, so a store keeping a
     * value that is no longer listed can still save its other edits.
     */
    protected function prepareForValidation(): void
    {
        $unchanged = app(RegistrationOptionsService::class)->unchangedFields($this->all(), $this->merchant());

        foreach (array_intersect($unchanged, MerchantProfileChangeService::ADMIN_EDITABLE_FIELDS) as $field) {
            $this->getInputSource()->remove($field);
        }
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => app(RegistrationOptionsService::class)->validateCity(
                $validator,
                $this->all(),
                $this->merchant()->province,
                $this->merchant()->city,
            ),
        ];
    }

    public function merchant(): Merchant
    {
        return $this->route('merchant');
    }

    /**
     * @return array<string, mixed>
     */
    public function profileFields(): array
    {
        return array_diff_key($this->validated(), MerchantProfileChangeService::documents());
    }

    /**
     * @return array<string, UploadedFile>
     */
    public function documentUploads(): array
    {
        return array_filter(
            array_intersect_key($this->validated(), MerchantProfileChangeService::documents()),
            fn ($file) => $file instanceof UploadedFile,
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...app(RegistrationOptionsService::class)->messages(),
            '*.prohibited' => 'This field cannot be changed here.',
            'tin.unique' => 'Another merchant already uses this TIN.',
            'contact_phone.regex' => 'Enter a Philippine mobile number such as 09171234567 or +639171234567.',
            'owner_phone.regex' => 'Enter a Philippine mobile number such as 09171234567 or +639171234567.',
        ];
    }
}
