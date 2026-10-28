<?php

namespace App\Http\Requests;

use App\Models\MerchantChangeRequest;
use App\Services\MerchantProfileChangeService;
use App\Services\RegistrationOptionsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class SubmitMerchantProfileChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', MerchantChangeRequest::class);
    }

    /**
     * Mirrors RegisterMerchantRequest for the editable fields. Fields that were required at
     * registration stay required when sent, so a change cannot blank them. Admin-controlled
     * fields are explicitly prohibited rather than silently ignored.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $phoneRegex = '/^(?:\\+63|0)9\\d{9}$/';

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
            // Replacement files, same limits as registration. Held privately until approved.
            'store_logo' => ['sometimes', 'image', 'mimes:jpg,jpeg,png', 'max:'.config('uploads.image_max_kilobytes')],
            'store_banner' => ['sometimes', 'image', 'mimes:jpg,jpeg,png', 'max:'.config('uploads.image_max_kilobytes')],
            'business_permit' => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('uploads.image_max_kilobytes')],
            'government_id' => ['sometimes', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('uploads.image_max_kilobytes')],
        ];

        // Dropdown fields must use the Super Admin-managed lists, as at registration.
        $rules = [...$rules, ...app(RegistrationOptionsService::class)->rules(partial: true)];

        foreach (MerchantProfileChangeService::ADMIN_CONTROLLED_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            // A new city must belong to the new province, or to the store's current one.
            fn (Validator $validator) => app(RegistrationOptionsService::class)->validateCity(
                $validator,
                $this->all(),
                $this->user()?->merchant?->province,
                $this->user()?->merchant?->city,
            ),
        ];
    }

    /**
     * Validated text fields (without file uploads).
     *
     * @return array<string, mixed>
     */
    public function profileFields(): array
    {
        return array_diff_key($this->validated(), MerchantProfileChangeService::documents());
    }

    /**
     * Validated replacement documents keyed by document name (store_logo, business_permit, …).
     *
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
            '*.prohibited' => 'This field can only be changed by a SofiaCart administrator.',
            'contact_phone.regex' => 'Enter a Philippine mobile number such as 09171234567 or +639171234567.',
            'owner_phone.regex' => 'Enter a Philippine mobile number such as 09171234567 or +639171234567.',
        ];
    }
}
