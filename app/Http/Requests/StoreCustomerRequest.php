<?php

namespace App\Http\Requests;

use App\Enums\CustomerGender;
use App\Enums\CustomerType;
use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;

class StoreCustomerRequest extends FormRequest
{
    public const MAX_TAGS = 20;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $addressFields = collect(array_keys(Customer::ADDRESS_FIELDS))
            ->reject(fn (string $field): bool => $field === 'line1')
            ->map(fn (string $field): string => "default_address.{$field}")
            ->implode(',');

        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'name' => ['required_without:first_name', 'nullable', 'string', 'max:255'],
            'first_name' => ['required_without:name', 'nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', $this->uniqueEmailRule()],
            'phone' => ['nullable', 'string', 'max:20'],
            'customer_type' => ['sometimes', 'required', Rule::enum(CustomerType::class)],
            'is_active' => ['sometimes', 'boolean'],
            'birthday' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'gender' => ['nullable', Rule::enum(CustomerGender::class)],
            'tin' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z][0-9A-Za-z\- ]*$/'],
            'address' => ['nullable', 'string', 'max:1000', 'prohibits:default_address'],
            'default_address' => ['nullable', 'array:'.implode(',', array_keys(Customer::ADDRESS_FIELDS))],
            'default_address.line1' => ['nullable', 'string', 'max:255', 'required_with:'.$addressFields],
            'default_address.line2' => ['nullable', 'string', 'max:255'],
            'default_address.city' => ['nullable', 'string', 'max:100'],
            'default_address.province' => ['nullable', 'string', 'max:100'],
            'default_address.postal_code' => ['nullable', 'string', 'max:20', 'regex:/^[0-9A-Za-z][0-9A-Za-z\- ]*$/'],
            'default_address.country' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:'.self::MAX_TAGS],
            'tags.*' => ['required', 'string', 'max:50', 'distinct:ignore_case'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'A customer with this email already exists in this store.',
            'default_address.line1.required_with' => 'Address line 1 is required when providing an address.',
            'tin.regex' => 'The TIN may only contain letters, numbers, spaces, and hyphens.',
        ];
    }

    /**
     * Map the validated payload onto customer columns, deriving the canonical name and legacy address.
     *
     * @return array<string, mixed>
     */
    public function customerAttributes(?Customer $existing = null): array
    {
        $data = $this->validated();
        $attributes = Arr::only($data, ['email', 'phone', 'customer_type', 'is_active', 'birthday', 'gender', 'tin', 'notes']);

        if (array_key_exists('first_name', $data) || array_key_exists('last_name', $data)) {
            $firstName = trim((string) (array_key_exists('first_name', $data) ? $data['first_name'] : $existing?->first_name));
            $lastName = trim((string) (array_key_exists('last_name', $data) ? $data['last_name'] : $existing?->last_name));

            if ($firstName === '') {
                throw ValidationException::withMessages([
                    'first_name' => ['The first name field is required when providing a last name.'],
                ]);
            }

            $attributes['first_name'] = $firstName;
            $attributes['last_name'] = $lastName === '' ? null : $lastName;
            $attributes['name'] = trim("{$firstName} {$lastName}");
        } elseif (array_key_exists('name', $data)) {
            $attributes['name'] = trim($data['name']);
            $attributes['first_name'] = null;
            $attributes['last_name'] = null;
        }

        if (array_key_exists('default_address', $data)) {
            $address = $data['default_address'] ?? [];

            foreach (Customer::ADDRESS_FIELDS as $field => $column) {
                $value = trim((string) ($address[$field] ?? ''));
                $attributes[$column] = $value === '' ? null : $value;
            }

            $attributes['address'] = Customer::formatAddress(Arr::only($attributes, Customer::ADDRESS_FIELDS));
        } elseif (array_key_exists('address', $data)) {
            $attributes['address'] = $data['address'];

            foreach (Customer::ADDRESS_FIELDS as $column) {
                $attributes[$column] = null;
            }
        }

        if (array_key_exists('tags', $data)) {
            $tags = collect($data['tags'] ?? [])
                ->map(fn (string $tag): string => trim($tag))
                ->filter()
                ->unique(fn (string $tag): string => mb_strtolower($tag))
                ->values()
                ->all();

            $attributes['tags'] = $tags === [] ? null : $tags;
        }

        return $attributes;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('email'))) {
            $normalized['email'] = mb_strtolower(trim($this->input('email')));
        }

        $isActive = $this->input('is_active');

        if (is_string($isActive) && in_array(strtolower($isActive), ['true', 'false', 'on', 'off', 'yes', 'no'], true)) {
            $normalized['is_active'] = filter_var($isActive, FILTER_VALIDATE_BOOLEAN);
        }

        $this->merge($normalized);
    }

    /**
     * The merchant the customer will belong to, used to scope email uniqueness.
     */
    protected function targetMerchantId(): ?int
    {
        $user = $this->user();

        if ($user?->isAdmin()) {
            return $this->filled('merchant_id') ? (int) $this->input('merchant_id') : null;
        }

        return $user?->merchant?->id;
    }

    protected function uniqueEmailRule(): Unique
    {
        return Rule::unique('customers', 'email')->where('merchant_id', $this->targetMerchantId());
    }
}
