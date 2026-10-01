<?php

namespace App\Models;

use App\Enums\CustomerGender;
use App\Enums\CustomerType;
use App\Enums\OrderPaymentStatus;
use App\Enums\RefundStatus;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    public const ADDRESS_FIELDS = [
        'line1' => 'address_line1',
        'line2' => 'address_line2',
        'city' => 'city',
        'province' => 'province',
        'postal_code' => 'postal_code',
        'country' => 'country',
    ];

    protected $fillable = [
        'merchant_id',
        'name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'customer_type',
        'is_active',
        'birthday',
        'gender',
        'tin',
        'address',
        'address_line1',
        'address_line2',
        'city',
        'province',
        'postal_code',
        'country',
        'notes',
        'tags',
    ];

    protected $attributes = [
        'customer_type' => 'regular',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'customer_type' => CustomerType::class,
            'gender' => CustomerGender::class,
            'is_active' => 'boolean',
            'birthday' => 'date',
            'tags' => 'array',
        ];
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Add order aggregates: `orders_count`, `paid_orders_count`, `total_spent` and `orders_max_ordered_at`.
     *
     * `total_spent` sums paid and partially refunded order totals, minus processed refunds on the
     * partially refunded orders. Fully refunded and unpaid orders contribute nothing.
     */
    #[Scope]
    protected function withOrderStats(Builder $query): void
    {
        $paidStatuses = [OrderPaymentStatus::Paid->value, OrderPaymentStatus::PartiallyRefunded->value];

        $query->withCount([
            'orders',
            'orders as paid_orders_count' => fn (Builder $orders) => $orders->whereIn('payment_status', $paidStatuses),
        ])
            ->withMax('orders', 'ordered_at')
            ->selectRaw(
                '(COALESCE((SELECT SUM(paid_orders.total_amount) FROM orders AS paid_orders'
                .' WHERE paid_orders.customer_id = customers.id AND paid_orders.payment_status IN (?, ?)), 0)'
                .' - COALESCE((SELECT SUM(processed_refunds.amount) FROM refunds AS processed_refunds'
                .' INNER JOIN orders AS refunded_orders ON refunded_orders.id = processed_refunds.order_id'
                .' WHERE refunded_orders.customer_id = customers.id AND refunded_orders.payment_status = ?'
                .' AND processed_refunds.status = ?), 0)) AS total_spent',
                [...$paidStatuses, OrderPaymentStatus::PartiallyRefunded->value, RefundStatus::Processed->value],
            );
    }

    /**
     * Build the single-line address kept in `address` for existing clients and order snapshots.
     *
     * @param  array<string, string|null>  $structuredAddress  Keyed by the customer's address columns.
     */
    public static function formatAddress(array $structuredAddress): ?string
    {
        $parts = array_filter(
            array_map(fn (?string $part): string => trim((string) $part), $structuredAddress),
            fn (string $part): bool => $part !== '',
        );

        return $parts === [] ? null : implode(', ', $parts);
    }
}
