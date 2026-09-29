<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
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
            'store_slug' => ['required', 'regex:'.$slugRegex, 'max:255', 'unique:merchants,store_slug'],
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
        ];
    }
}
