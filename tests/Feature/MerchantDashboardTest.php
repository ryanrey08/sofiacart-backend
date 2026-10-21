<?php

namespace Tests\Feature;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\RefundStatus;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MerchantDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/merchant/dashboard')->assertUnauthorized();
    }

    public function test_returns_store_metrics_scoped_to_the_signed_in_merchant(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);

        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => ProductStatus::Active,
            'track_inventory' => true,
            'stock_quantity' => 2,
            'low_stock_threshold' => 5,
        ]);
        // An out-of-stock product so inventory counts are exercised.
        Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => ProductStatus::Active,
            'track_inventory' => true,
            'stock_quantity' => 0,
            'low_stock_threshold' => 5,
        ]);

        // Two paid orders inside the default 30-day window.
        foreach ([100.00, 200.00] as $amount) {
            $order = Order::factory()->create([
                'merchant_id' => $merchant->id,
                'customer_id' => $customer->id,
                'status' => OrderStatus::Completed,
                'payment_status' => OrderPaymentStatus::Paid,
                'total_amount' => $amount,
                'ordered_at' => now(),
            ]);
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'quantity' => 2,
                'unit_price' => $amount / 2,
                'total_price' => $amount,
            ]);
        }
        // A pending order that must not count as paid.
        Order::factory()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'total_amount' => 999.00,
            'ordered_at' => now(),
        ]);

        // Collected payments (captured) + a pending payment that must be excluded.
        $completed = Payment::factory()->create(['merchant_id' => $merchant->id, 'status' => PaymentStatus::Completed, 'amount' => 150.00]);
        Payment::factory()->create(['merchant_id' => $merchant->id, 'status' => PaymentStatus::Pending, 'amount' => 777.00, 'paid_at' => null]);

        // A processed refund that must be excluded from net revenue.
        Refund::create([
            'merchant_id' => $merchant->id,
            'payment_id' => $completed->id,
            'reference' => 'RFD-TEST-1',
            'amount' => 50.00,
            'status' => RefundStatus::Processed,
            'refunded_at' => now(),
        ]);

        // Another merchant's data must never leak in.
        $other = Merchant::factory()->create();
        Order::factory()->create(['merchant_id' => $other->id, 'status' => OrderStatus::Completed, 'payment_status' => OrderPaymentStatus::Paid, 'total_amount' => 5000.00, 'ordered_at' => now()]);
        Payment::factory()->create(['merchant_id' => $other->id, 'status' => PaymentStatus::Completed, 'amount' => 5000.00]);
        Product::factory()->create(['merchant_id' => $other->id]);

        Sanctum::actingAs($merchant->user);

        $response = $this->getJson('/api/v1/merchant/dashboard')->assertOk();

        // Range metrics.
        $response->assertJsonPath('data.metrics.orders_count', 3)
            ->assertJsonPath('data.metrics.paid_orders_count', 2)
            ->assertJsonPath('data.metrics.gross_sales', '1299.00')
            ->assertJsonPath('data.metrics.payments_collected', '150.00')
            ->assertJsonPath('data.metrics.payments_count', 1)
            ->assertJsonPath('data.metrics.refunds_processed', '50.00')
            ->assertJsonPath('data.metrics.net_revenue', '100.00')
            ->assertJsonPath('data.currency', config('payments.currency'));

        // All-time totals, scoped to this merchant.
        $response->assertJsonPath('data.totals.orders', 3)
            ->assertJsonPath('data.totals.pending_orders', 1)
            ->assertJsonPath('data.totals.customers', 1)
            ->assertJsonPath('data.totals.products', 2)
            ->assertJsonPath('data.totals.pending_payments', 1)
            ->assertJsonPath('data.totals.inventory.out_of_stock', 1)
            ->assertJsonPath('data.totals.inventory.low_stock', 1);

        // Top products come from real order items.
        $this->assertSame($product->id, $response->json('data.breakdowns.top_products.0.id'));
        $this->assertSame(4, $response->json('data.breakdowns.top_products.0.quantity_sold'));
        $this->assertSame('300.00', $response->json('data.breakdowns.top_products.0.revenue'));

        // Recent orders are the merchant's own, newest first.
        $this->assertCount(3, $response->json('data.recent.orders'));
    }

    public function test_empty_store_returns_zeroed_metrics(): void
    {
        $merchant = Merchant::factory()->create();
        Sanctum::actingAs($merchant->user);

        $this->getJson('/api/v1/merchant/dashboard')->assertOk()
            ->assertJsonPath('data.metrics.orders_count', 0)
            ->assertJsonPath('data.metrics.gross_sales', '0.00')
            ->assertJsonPath('data.metrics.net_revenue', '0.00')
            ->assertJsonPath('data.totals.orders', 0)
            ->assertJsonPath('data.totals.products', 0)
            ->assertJsonPath('data.breakdowns.top_products', [])
            ->assertJsonPath('data.recent.orders', []);
    }

    public function test_validates_date_filters(): void
    {
        $merchant = Merchant::factory()->create();
        Sanctum::actingAs($merchant->user);

        $this->getJson('/api/v1/merchant/dashboard?date_from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_from');

        $this->getJson('/api/v1/merchant/dashboard?date_from=2026-01-02&date_to=2026-01-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_to');
    }

    public function test_date_range_limits_sales_metrics(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);

        Order::factory()->create(['merchant_id' => $merchant->id, 'customer_id' => $customer->id, 'total_amount' => 100.00, 'ordered_at' => now()->subDays(2)]);
        Order::factory()->create(['merchant_id' => $merchant->id, 'customer_id' => $customer->id, 'total_amount' => 400.00, 'ordered_at' => now()->subDays(90)]);

        Sanctum::actingAs($merchant->user);

        $from = now()->subDays(7)->toDateString();
        $to = now()->toDateString();
        $this->getJson("/api/v1/merchant/dashboard?date_from={$from}&date_to={$to}")
            ->assertOk()
            ->assertJsonPath('data.metrics.orders_count', 1)
            ->assertJsonPath('data.metrics.gross_sales', '100.00')
            // Totals stay all-time regardless of the range.
            ->assertJsonPath('data.totals.orders', 2);
    }
}
