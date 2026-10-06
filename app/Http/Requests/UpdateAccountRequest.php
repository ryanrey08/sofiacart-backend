<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The signed-in merchant's personal account details (users table). The user is always the
 * authenticated one; no user id is accepted. Role, status and password can't be set here.
 */
class UpdateAccountRequest extends FormRequest
{
    /**
     * Fields that exist on the account but are never changed through this endpoint.
     */
    public const PROTECTED_FIELDS = [
        'id',
        'user_id',
        'role',
        'is_active',
        'password',
        'password_confirmation',
        'email_verified_at',
        'last_login_at',
        'remember_token',
        'merchant',
        'merchant_id',
        'status',
    ];

    public function authorize(): bool
    {
        return $this->user()?->role === UserRole::Merchant;
    }

    /**
     * Mirrors RegisterMerchantRequest for the account fields.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->getKey())],
            'phone' => ['sometimes', 'required', 'regex:/^(?:\+63|0)9\d{9}$/'],
            // Required (and checked) only when the email address changes; see after().
            'current_password' => ['nullable', 'string'],
        ];

        foreach (self::PROTECTED_FIELDS as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }

    /**
     * The email address is the login identifier, so changing it requires the current password.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('email') || ! $this->emailChanges()) {
                    return;
                }

                $password = (string) $this->input('current_password');

                if ($password === '') {
                    $validator->errors()->add('current_password', 'Enter your current password to change your email address.');
                } elseif (! Hash::check($password, $this->user()->password)) {
                    $validator->errors()->add('current_password', 'The current password is incorrect.');
                }
            },
        ];
    }

    public function emailChanges(): bool
    {
        return $this->filled('email')
            && Str::lower(trim((string) $this->input('email'))) !== Str::lower((string) $this->user()->email);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            '*.prohibited' => 'This field cannot be changed from your account settings.',
            'phone.regex' => 'Enter a Philippine mobile number such as 09171234567 or +639171234567.',
            'email.unique' => 'This email address is already used by another account.',
        ];
    }
}
