<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\Concerns\IncludesMerchantSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AdminCustomerResource extends JsonResource
{
    use IncludesMerchantSummary;

    /**
     * Platform admins see masked contact details only; full customer records stay with the merchant.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'merchant' => $this->merchantSummary(),
            'name' => $this->name,
            'email_masked' => $this->email ? Str::mask($this->email, '*', 2, max(strlen($this->email) - 6, 1)) : null,
            'phone_masked' => $this->phone ? Str::mask($this->phone, '*', 3, max(strlen($this->phone) - 5, 1)) : null,
            'customer_type' => $this->customer_type?->value,
            'status' => $this->is_active ? 'active' : 'inactive',
            'orders_count' => $this->whenCounted('orders', $this->orders_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
