<?php

namespace App\Http\Resources\Admin;

use App\Http\Controllers\Api\Admin\MerchantManagementController;
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
            'customers_count' => $this->whenCounted('customers', $this->customers_count),
            'payments_count' => $this->whenCounted('payments', $this->payments_count),
            'transactions_count' => $this->whenCounted('transactions', $this->transactions_count),
            'refunds_count' => $this->whenCounted('refunds', $this->refunds_count),
            'payments_sum_amount' => $this->whenAggregated('payments', 'amount', 'sum', $this->payments_sum_amount),
            'documents' => collect(MerchantManagementController::DOCUMENTS)
                ->filter(fn (string $column) => filled($this->{$column}))
                ->keys()
                ->values()
                ->all(),
        ]);
    }
}
