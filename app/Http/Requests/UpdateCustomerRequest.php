<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Validation\Rules\Unique;

class UpdateCustomerRequest extends StoreCustomerRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
        ];
    }

    protected function targetMerchantId(): ?int
    {
        if ($this->user()?->isAdmin() && ! $this->filled('merchant_id')) {
            return Customer::whereKey((int) $this->route('customer'))->value('merchant_id');
        }

        return parent::targetMerchantId();
    }

    protected function uniqueEmailRule(): Unique
    {
        return parent::uniqueEmailRule()->ignore((int) $this->route('customer'));
    }
}
