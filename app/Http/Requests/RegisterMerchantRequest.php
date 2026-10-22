<?php

namespace App\Http\Requests;

use App\Enums\LegalDocumentAudience;
use App\Models\Merchant;
use App\Services\LegalDocumentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Normalized so "Owner@Example.com" can't register a second account for the same mailbox.
        if (is_string($this->email)) {
            $this->merge(['email' => Str::lower(trim($this->email))]);
        }

        if (is_string($this->social_links)) {
            $decoded = json_decode($this->social_links, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->merge(['social_links' => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        $phoneRegex = '/^(?:\\+63|0)9\\d{9}$/';
        $slugRegex = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['required', 'regex:'.$phoneRegex],
            'business_name' => ['required', 'string', 'max:255'],
            'business_type' => ['required', 'string', 'max:255'],
            'business_permit_number' => ['required', 'string', 'max:255'],
            'tin' => ['required', 'string', 'max:50', 'unique:merchants,tin'],
            'business_category' => ['required', 'string', 'max:255'],
            'business_address' => ['required', 'string'],
            'city' => ['required', 'string', 'max:255'],
            'province' => ['required', 'string', 'max:255'],
            'zip_code' => ['required', 'string', 'max:10'],
            'store_name' => ['required', 'string', 'max:255'],
            // The slug is the store's subdomain (<slug>.sofiacart.ph), so it must be a valid DNS label
            // (at most 63 characters) and not one of the platform's own subdomains.
            'store_slug' => ['required', 'regex:'.$slugRegex, 'max:63', Rule::notIn(Merchant::RESERVED_STORE_SLUGS), 'unique:merchants,store_slug'],
            'store_category' => ['required', 'string', 'max:255'],
            'store_description' => ['nullable', 'string'],
            'store_address' => ['required', 'string'],
            'contact_phone' => ['required', 'regex:'.$phoneRegex],
            'contact_email' => ['required', 'email', 'max:255'],
            'social_links' => ['nullable', 'array'],
            'social_links.*' => ['nullable', 'url', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_position' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_phone' => ['required', 'regex:'.$phoneRegex],
            'owner_birth_date' => ['required', 'date', 'before:today'],
            'government_id_type' => ['required', 'string', 'max:255'],
            'government_id_number' => ['required', 'string', 'max:255'],
            'government_id_expiry_date' => ['required', 'date', 'after:today'],
            'business_permit' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'store_logo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'store_banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120'],
            'government_id' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            ...$this->legalRules(),
        ];
    }

    /**
     * The merchant must accept the published merchant Terms and acknowledge the published merchant
     * Privacy Policy, naming the exact versions shown on the form (recorded as consent records).
     *
     * @return array<string, array<int, string>>
     */
    protected function legalRules(): array
    {
        if (! $this->requiresLegalAcceptance()) {
            return [];
        }

        return [
            'accept_terms' => ['accepted'],
            'terms_document_id' => ['required', 'integer'],
            'acknowledge_privacy' => ['accepted'],
            'privacy_document_id' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->requiresLegalAcceptance()
                    || $validator->errors()->hasAny(['terms_document_id', 'privacy_document_id'])) {
                    return;
                }

                $errors = app(LegalDocumentService::class)->registrationErrors(
                    LegalDocumentAudience::Merchant,
                    $this->input('terms_document_id'),
                    $this->input('privacy_document_id'),
                );

                foreach ($errors as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'store_slug.not_in' => 'This store URL is reserved. Please choose another one.',
            'store_slug.max' => 'The store URL may not be longer than 63 characters.',
            'accept_terms.accepted' => 'You must accept the Merchant Terms and Conditions to register.',
            'acknowledge_privacy.accepted' => 'You must acknowledge that you have read the Merchant Data Privacy Policy to register.',
        ];
    }

    public function requiresLegalAcceptance(): bool
    {
        return (bool) config('legal_documents.require_registration_acceptance');
    }
}
