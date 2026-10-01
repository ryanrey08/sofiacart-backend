<?php

namespace App\Http\Resources;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Customer
 */
class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'merchant_id' => $this->merchant_id,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'customer_type' => $this->customer_type?->value,
            'is_active' => (bool) $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'birthday' => $this->birthday?->toDateString(),
            'gender' => $this->gender?->value,
            'tin' => $this->tin,
            'address' => $this->address,
            'default_address' => collect(Customer::ADDRESS_FIELDS)
                ->map(fn (string $column) => $this->{$column})
                ->all(),
            'notes' => $this->notes,
            'tags' => $this->tags ?? [],
            'orders_count' => $this->whenCounted('orders'),
            'paid_orders_count' => $this->whenHas('paid_orders_count', fn () => (int) $this->paid_orders_count),
            'total_spent' => $this->whenHas('total_spent', fn () => number_format((float) $this->total_spent, 2, '.', '')),
            'last_order_at' => $this->whenHas(
                'orders_max_ordered_at',
                fn () => $this->orders_max_ordered_at ? Carbon::parse($this->orders_max_ordered_at)->toISOString() : null,
            ),
            'recent_orders' => OrderResource::collection($this->whenLoaded('recentOrders')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
