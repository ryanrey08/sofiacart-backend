<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\MerchantResource;
use App\Http\Resources\UserResource;
use Illuminate\Http\Request;

class AdminMerchantResource extends MerchantResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'user' => UserResource::make($this->whenLoaded('user')),
            'orders_count' => $this->whenCounted('orders', $this->orders_count),
            'products_count' => $this->whenCounted('products', $this->products_count),
            'payments_sum_amount' => $this->whenAggregated('payments', 'amount', 'sum', $this->payments_sum_amount),
        ]);
    }
}
