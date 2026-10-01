<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Validation\Rules\Unique;

class UpdateCategoryRequest extends StoreCategoryRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', 'regex:'.self::SLUG_PATTERN, $this->uniqueSlugRule()],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }

    protected function targetMerchantId(): ?int
    {
        if ($this->user()?->isAdmin() && ! $this->filled('merchant_id')) {
            return Category::whereKey((int) $this->route('category'))->value('merchant_id');
        }

        return parent::targetMerchantId();
    }

    protected function uniqueSlugRule(): Unique
    {
        return parent::uniqueSlugRule()->ignore((int) $this->route('category'));
    }
}
