<?php

namespace App\Http\Requests;

use App\Models\Category;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreCategoryRequest extends FormRequest
{
    public const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public const DESCRIPTION_MAX_TEXT_LENGTH = 500;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_id' => ['nullable', 'integer', 'exists:merchants,id'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:'.self::SLUG_PATTERN, $this->uniqueSlugRule()],
            'description' => $this->descriptionRules(),
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'show_in_nav' => ['sometimes', 'boolean'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The slug may only contain lowercase letters, numbers, and hyphens.',
            'slug.unique' => 'The slug has already been taken in this store.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $booleans = [];

        foreach (['is_active', 'show_in_nav', 'remove_image'] as $field) {
            $value = $this->input($field);

            if (is_string($value) && in_array(strtolower($value), ['true', 'false', 'on', 'off', 'yes', 'no'], true)) {
                $booleans[$field] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }

        $this->merge($booleans);
    }

    /**
     * The merchant the category will belong to, used to scope slug uniqueness.
     */
    protected function targetMerchantId(): ?int
    {
        $user = $this->user();

        if ($user?->isAdmin()) {
            return $this->filled('merchant_id') ? (int) $this->input('merchant_id') : null;
        }

        return $user?->merchant?->id;
    }

    protected function uniqueSlugRule(): Unique
    {
        return Rule::unique('categories', 'slug')->where('merchant_id', $this->targetMerchantId());
    }

    /**
     * @return list<mixed>
     */
    protected function descriptionRules(): array
    {
        return [
            'nullable',
            'string',
            'max:10000',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (Category::descriptionTextLength($value) > self::DESCRIPTION_MAX_TEXT_LENGTH) {
                    $fail('The description may not be greater than '.self::DESCRIPTION_MAX_TEXT_LENGTH.' characters.');
                }
            },
        ];
    }
}
