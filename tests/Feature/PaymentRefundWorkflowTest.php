<?php

namespace Tests\Feature;

use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Enums\RefundStatus;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_creation_synchronizes_order_payment_status(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $order = Order::factory()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'total_amount' => 300.00,
            'payment_status' => OrderPaymentStatus::Unpaid,
        ]);

        Sanctum::actingAs($merchant->user);

        // Record a completed payment covering the full order total
        $response = $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'gateway' => 'card',
            'status' => PaymentStatus::Completed->value,
            'amount' => 300.00,
            'reference' => 'PAY-300-FULL',
            'paid_at' => now()->toIso8601String(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.merchant_id', $merchant->id)
            ->assertJsonPath('data.amount', '300.00')
            ->assertJsonPath('data.status', PaymentStatus::Completed->value);

        // Order payment_status should now be 'paid'
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'payment_status' => OrderPaymentStatus::Paid->value,
        ]);
    }

    public function test_sensitive_gateway_metadata_is_redacted(): void
    {
        $merchant = Merchant::factory()->create();
        $order = Order::factory()->create(['merchant_id' => $merchant->id, 'status' => OrderStatus::Pending]);

        Sanctum::actingAs($merchant->user);

        $response = $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'gateway' => 'stripe',
            'status' => PaymentStatus::Completed->value,
            'amount' => 100.00,
            'reference' => 'PAY-SECURE-001',
            'metadata' => [
                'public_charge_id' => 'ch_123456789',
                'secret_key' => 'sk_live_secret_value',
                'card_token' => 'tok_visa_4242',
                'gateway_auth' => 'bearer auth_token_secret',
            ],
        ]);

        $response->assertCreated();

        // Safe field is preserved
        $response->assertJsonPath('data.metadata.public_charge_id', 'ch_123456789');

        // Sensitive fields are masked
        $response->assertJsonPath('data.metadata.secret_key', '[REDACTED]');
        $response->assertJsonPath('data.metadata.card_token', '[REDACTED]');
        $response->assertJsonPath('data.metadata.gateway_auth', '[REDACTED]');
    }

    public function test_refund_lifecycle_enforces_balance_without_restoring_unreturned_inventory(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => ProductStatus::Active,
            'price' => 100.00,
            'stock_quantity' => 10,
        ]);

        Sanctum::actingAs($merchant->user);

        // 1. Create order for 2 products ($200.00)
        $orderResponse = $this->postJson('/api/v1/orders', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 100.00,
                ],
            ],
        ]);
        $orderResponse->assertCreated();
        $orderId = $orderResponse->json('data.id');

        // Stock is now 8
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 8]);

        // 2. Complete payment of $200.00
        $paymentResponse = $this->postJson('/api/v1/payments', [
            'order_id' => $orderId,
            'gateway' => 'card',
            'status' => PaymentStatus::Completed->value,
            'amount' => 200.00,
            'reference' => 'PAY-REFUND-TEST',
        ]);
        $paymentResponse->assertCreated();
        $paymentId = $paymentResponse->json('data.id');

        // Order is now paid
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_status' => OrderPaymentStatus::Paid->value]);

        // 3. Try to refund $250.00 (exceeds balance)
        $this->postJson('/api/v1/refunds', [
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'reference' => 'REF-EXCEED',
            'amount' => 250.00,
            'status' => RefundStatus::Processed->value,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);

        // 4. Create partial refund of $50.00
        $partialRefundResponse = $this->postJson('/api/v1/refunds', [
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'reference' => 'REF-PARTIAL-50',
            'amount' => 50.00,
            'status' => RefundStatus::Processed->value,
        ]);
        $partialRefundResponse->assertCreated();

        // Payment and Order should be partially refunded
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => PaymentStatus::PartiallyRefunded->value]);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_status' => OrderPaymentStatus::PartiallyRefunded->value]);

        // Stock remains 8 for partial refund
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 8]);

        // 5. Create second refund for remaining $150.00
        $fullRefundResponse = $this->postJson('/api/v1/refunds', [
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'reference' => 'REF-FULL-150',
            'amount' => 150.00,
            'status' => RefundStatus::Processed->value,
        ]);
        $fullRefundResponse->assertCreated();

        // Payment and Order should be refunded
        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => PaymentStatus::Refunded->value]);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'payment_status' => OrderPaymentStatus::Refunded->value,
            'inventory_restored' => false,
        ]);

        // A financial refund alone does not prove that physical items were returned.
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => 8]);
    }

    public function test_transaction_cross_relation_integrity(): void
    {
        $merchant = Merchant::factory()->create();

        $order = Order::factory()->create(['merchant_id' => $merchant->id, 'status' => OrderStatus::Pending, 'total_amount' => 100.00]);
        $otherOrder = Order::factory()->create(['merchant_id' => $merchant->id]);

        Sanctum::actingAs($merchant->user);

        // Transactions are written by the payment service only; clients cannot create them.
        $this->postJson('/api/v1/transactions', [
            'order_id' => $otherOrder->id,
            'reference' => 'TXN-MANUAL',
            'type' => TransactionType::Payment->value,
            'status' => TransactionStatus::Completed->value,
            'amount' => 100.00,
        ])->assertStatus(405);

        // A recorded payment produces a transaction linked to the same payment and order.
        $paymentId = $this->postJson('/api/v1/payments', [
            'order_id' => $order->id,
            'method' => 'gcash',
            'status' => PaymentStatus::Completed->value,
            'amount' => 100.00,
        ])->assertCreated()->json('data.id');

        $transaction = $this->getJson("/api/v1/transactions?payment_id={$paymentId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_id', $order->id)
            ->assertJsonPath('data.0.type', TransactionType::Payment->value)
            ->json('data.0');

        // Links, amounts and statuses are immutable.
        $this->patchJson("/api/v1/transactions/{$transaction['id']}", [
            'order_id' => $otherOrder->id,
            'metadata' => ['note' => 'moved'],
        ])->assertStatus(422)->assertJsonValidationErrors(['order_id']);
    }

    public function test_merchant_isolation_for_payments_and_refunds(): void
    {
        $merchant = Merchant::factory()->create();
        $otherMerchant = Merchant::factory()->create();

        $otherOrder = Order::factory()->create(['merchant_id' => $otherMerchant->id]);
        $otherPayment = Payment::factory()->create([
            'merchant_id' => $otherMerchant->id,
            'order_id' => $otherOrder->id,
            'amount' => 150.00,
            'status' => PaymentStatus::Completed,
        ]);

        Sanctum::actingAs($merchant->user);

        // Merchant A cannot view or modify Merchant B's payment
        $this->getJson("/api/v1/payments/{$otherPayment->id}")->assertNotFound();

        // Merchant A cannot refund Merchant B's payment
        $this->postJson('/api/v1/refunds', [
            'payment_id' => $otherPayment->id,
            'reference' => 'REF-UNAUTH',
            'amount' => 50.00,
            'status' => RefundStatus::Processed->value,
        ])->assertNotFound();
    }
}
