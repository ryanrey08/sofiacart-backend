<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AdminCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'name' => $this->name,
            'email_masked' => $this->email ? Str::mask($this->email, '*', 2, max(strlen($this->email) - 6, 1)) : null,
            'phone_masked' => $this->phone ? Str::mask($this->phone, '*', 3, max(strlen($this->phone) - 5, 1)) : null,
            'orders_count' => $this->whenCounted('orders', $this->orders_count),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
