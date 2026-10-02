<?php

namespace Tests\Feature;

use App\Admin\AdminRoleRegistry;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProductStatus;
use App\Models\AdminRole;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TransactionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_and_refunds_write_ledger_rows_that_keep_payment_and_order_in_sync(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id, 'name' => 'Juan Dela Cruz', 'email' => 'juan@example.com']);
        $product = Product::factory()->create(['merchant_id' => $merchant->id, 'status' => ProductStatus::Active, 'price' => 500, 'stock_quantity' => 10]);
        Sanctum::actingAs($merchant->user);

        $order = $this->postJson('/api/v1/orders', ['customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 2]]])
            ->assertCreated()->json('data');
        $this->assertDatabaseCount('transactions', 0);

        $paymentId = $this->postJson('/api/v1/payments', ['order_id' => $order['id'], 'method' => 'card', 'amount' => 1000, 'reference' => 'TXN789456'])
            ->assertCreated()->json('data.id');
        $this->assertDatabaseCount('transactions', 0);

        $this->patchJson("/api/v1/payments/{$paymentId}/status", ['status' => 'completed', 'gateway_reference' => 'ch_3N8v9kL2'])->assertOk();

        $payment = $this->getJson("/api/v1/transactions?payment_id={$paymentId}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'payment')
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.amount', '1000.00')
            ->assertJsonPath('data.0.currency', 'PHP')
            ->assertJsonPath('data.0.payment_method', 'card')
            ->assertJsonPath('data.0.payment.reference', 'TXN789456')
            ->assertJsonPath('data.0.payment.gateway_reference', 'ch_3N8v9kL2')
            ->assertJsonPath('data.0.order.order_number', $order['order_number'])
            ->assertJsonPath('data.0.order.payment_status', 'paid')
            ->assertJsonPath('data.0.customer.name', 'Juan Dela Cruz')
            ->assertJsonPath('data.0.customer.email', 'juan@example.com')
            ->json('data.0');

        $partialId = $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 300, 'reason' => 'Wrong Item'])->assertCreated()->json('data.id');
        $this->assertDatabaseCount('transactions', 1);
        $this->patchJson("/api/v1/refunds/{$partialId}/status", ['status' => 'processed'])->assertOk();

        $this->getJson("/api/v1/transactions?refund_id={$partialId}")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'refund')
            ->assertJsonPath('data.0.amount', '300.00')
            ->assertJsonPath('data.0.refund.reason', 'Wrong Item')
            ->assertJsonPath('data.0.payment.status', 'partially_refunded')
            ->assertJsonPath('data.0.order.payment_status', 'partially_refunded');

        $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 700.01, 'status' => 'processed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->assertDatabaseCount('transactions', 2);

        $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 700, 'status' => 'processed', 'cancel_order' => true])
            ->assertCreated()->assertJsonPath('data.order.status', 'cancelled');

        $this->assertSame(PaymentStatus::Refunded, Payment::find($paymentId)->status);
        $this->assertSame(OrderPaymentStatus::Refunded, Order::find($order['id'])->payment_status);
        $this->assertSame(10, $product->fresh()->stock_quantity);

        $this->getJson("/api/v1/transactions/{$payment['id']}")->assertOk()
            ->assertJsonPath('data.id', $payment['id'])
            ->assertJsonCount(3, 'meta.history')
            ->assertJsonPath('meta.history.0.type', 'payment')
            ->assertJsonPath('meta.history.2.amount', '700.00')
            ->assertJsonPath('meta.timeline.0.event', 'order_created')
            ->assertJsonPath('meta.timeline.1.event', 'payment_received')
            ->assertJsonPath('meta.timeline.2.event', 'refund_issued')
            ->assertJsonPath('meta.timeline.3.event', 'refund_issued')
            ->assertJsonPath('meta.timeline.1.transaction_id', $payment['id']);

        $this->getJson('/api/v1/transactions/summary')->assertOk()
            ->assertJsonPath('data.total_transactions', 3)
            ->assertJsonPath('data.sales_orders', 1)
            ->assertJsonPath('data.by_type.payment', 1)
            ->assertJsonPath('data.by_type.refund', 2)
            ->assertJsonPath('data.collected_amount', '1000.00')
            ->assertJsonPath('data.refunded_amount', '1000.00')
            ->assertJsonPath('data.net_amount', '0.00');
    }

    public function test_failed_payment_is_recorded_as_a_failed_transaction_without_paying_the_order(): void
    {
        [$merchant, $order] = $this->merchantWithOrder(400);
        Sanctum::actingAs($merchant->user);

        $declinedId = $this->postJson('/api/v1/payments', ['order_id' => $order->id, 'method' => 'gcash', 'amount' => 400])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/payments/{$declinedId}/status", ['status' => 'failed', 'reason' => 'Insufficient balance'])->assertOk();
        $this->postJson('/api/v1/payments', ['order_id' => $order->id, 'method' => 'maya', 'status' => 'failed', 'amount' => 400, 'failure_reason' => 'Timeout'])
            ->assertCreated();

        $this->getJson('/api/v1/transactions?status=failed')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.type', 'payment');
        $this->getJson('/api/v1/transactions?status=completed')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);

        $this->getJson('/api/v1/transactions/summary')->assertOk()
            ->assertJsonPath('data.by_status.failed', 2)
            ->assertJsonPath('data.collected_amount', '0.00');

        $this->postJson('/api/v1/refunds', ['payment_id' => $declinedId, 'amount' => 100])
            ->assertUnprocessable()->assertJsonValidationErrors(['payment_id']);
    }

    public function test_duplicate_verifications_and_callbacks_never_duplicate_ledger_rows(): void
    {
        [$merchant, $order] = $this->merchantWithOrder(250);
        Sanctum::actingAs($merchant->user);
        $paymentId = $this->postJson('/api/v1/payments', ['order_id' => $order->id, 'method' => 'gcash', 'amount' => 250])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/payments/{$paymentId}/status", ['status' => 'completed'])->assertOk();
        $this->patchJson("/api/v1/payments/{$paymentId}/status", ['status' => 'completed'])->assertOk();

        $service = app(PaymentService::class);
        $payment = Payment::findOrFail($paymentId);
        $service->transition($payment, PaymentStatus::Completed);
        $first = $service->recordPaymentTransaction($payment);
        $second = $service->recordPaymentTransaction($payment->fresh());

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Transaction::where('payment_id', $paymentId)->count());
        $this->assertSame('payment:'.$paymentId, $first->source_key);

        $refundId = $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 50])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'processed'])->assertOk();
        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'processed'])->assertOk();
        $this->assertSame(1, Transaction::where('refund_id', $refundId)->count());

        $this->expectException(UniqueConstraintViolationException::class);
        Transaction::create([
            'merchant_id' => $merchant->id, 'payment_id' => $paymentId, 'reference' => 'TXN-DUPLICATE',
            'source_key' => 'payment:'.$paymentId, 'type' => 'payment', 'status' => 'completed', 'amount' => 250,
        ]);
    }

    public function test_transactions_are_immutable_except_for_metadata(): void
    {
        [$merchant, $order] = $this->merchantWithOrder(100);
        Sanctum::actingAs($merchant->user);
        $this->postJson('/api/v1/payments', ['order_id' => $order->id, 'method' => 'cash', 'status' => 'completed', 'amount' => 100])->assertCreated();
        $transaction = Transaction::firstOrFail();

        $this->postJson('/api/v1/transactions', ['type' => 'payment', 'status' => 'completed', 'amount' => 5, 'reference' => 'X'])->assertStatus(405);
        $this->deleteJson("/api/v1/transactions/{$transaction->id}")->assertStatus(405);

        $this->patchJson("/api/v1/transactions/{$transaction->id}", ['status' => 'failed', 'amount' => 1, 'metadata' => ['note' => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'amount']);
        $this->patchJson("/api/v1/transactions/{$transaction->id}", [])->assertUnprocessable()->assertJsonValidationErrors(['metadata']);

        $this->patchJson("/api/v1/transactions/{$transaction->id}", ['metadata' => ['reconciled' => true, 'card_number' => '4111111111111111']])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.amount', '100.00')
            ->assertJsonPath('data.metadata.source', 'payment')
            ->assertJsonPath('data.metadata.reconciled', true)
            ->assertJsonPath('data.metadata.card_number', '[REDACTED]');
        $this->assertStringNotContainsString('4111111111111111', (string) $transaction->fresh()->getRawOriginal('metadata'));
    }

    public function test_transactions_are_scoped_to_the_merchant_and_permission_checked(): void
    {
        $this->seed(AdminAuthorizationSeeder::class);
        [$merchant, $order] = $this->merchantWithOrder(100);
        $payment = Payment::factory()->create(['merchant_id' => $merchant->id, 'order_id' => $order->id, 'amount' => 100]);
        $transaction = app(PaymentService::class)->recordPaymentTransaction($payment);

        Sanctum::actingAs(Merchant::factory()->create()->user);
        $this->getJson('/api/v1/transactions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/transactions?merchant_id={$merchant->id}")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/transactions/{$transaction->id}")->assertNotFound();
        $this->patchJson("/api/v1/transactions/{$transaction->id}", ['metadata' => ['x' => 1]])->assertNotFound();
        $this->getJson('/api/v1/transactions/summary')->assertOk()->assertJsonPath('data.total_transactions', 0);

        Sanctum::actingAs($this->adminWithRole(AdminRoleRegistry::PRODUCT_MANAGER), ['admin'], 'sanctum');
        $this->getJson('/api/v1/transactions')->assertForbidden();
        $this->getJson('/api/admin/transactions')->assertForbidden();

        Sanctum::actingAs($this->adminWithRole(AdminRoleRegistry::CUSTOMER_SUPPORT), ['admin'], 'sanctum');
        $this->getJson("/api/admin/transactions?merchant_id={$merchant->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/admin/transactions/{$transaction->id}")->assertOk();
        $this->patchJson("/api/admin/transactions/{$transaction->id}", ['metadata' => ['x' => 1]])->assertForbidden();

        Sanctum::actingAs($this->adminWithRole(AdminRoleRegistry::ORDER_MANAGER), ['admin'], 'sanctum');
        $this->patchJson("/api/admin/transactions/{$transaction->id}", ['metadata' => ['reconciled' => true]])->assertOk();
    }

    public function test_transactions_can_be_paginated_filtered_searched_and_sorted(): void
    {
        $merchant = Merchant::factory()->create();
        $maria = Customer::factory()->create(['merchant_id' => $merchant->id, 'name' => 'Maria Santos']);
        $pedro = Customer::factory()->create(['merchant_id' => $merchant->id, 'name' => 'Pedro Reyes']);
        $mariaOrder = Order::factory()->create(['merchant_id' => $merchant->id, 'customer_id' => $maria->id, 'order_number' => 'SC-2026-00124', 'status' => OrderStatus::Pending, 'total_amount' => 799]);
        $pedroOrder = Order::factory()->create(['merchant_id' => $merchant->id, 'customer_id' => $pedro->id, 'order_number' => 'SC-2026-00123', 'status' => OrderStatus::Pending, 'total_amount' => 1299]);
        Sanctum::actingAs($merchant->user);

        $this->travelTo(now()->subDays(3));
        $this->postJson('/api/v1/payments', ['order_id' => $pedroOrder->id, 'method' => 'maya', 'status' => 'completed', 'amount' => 1299])->assertCreated();
        $this->travelBack();
        $mariaPayment = $this->postJson('/api/v1/payments', ['order_id' => $mariaOrder->id, 'method' => 'gcash', 'status' => 'completed', 'amount' => 799])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/refunds', ['payment_id' => $mariaPayment, 'amount' => 199, 'status' => 'processed'])->assertCreated();

        $this->getJson('/api/v1/transactions?per_page=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.type', 'refund');
        $this->getJson('/api/v1/transactions?per_page=2&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '1299.00');

        $this->getJson('/api/v1/transactions?type=refund')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '199.00');
        $this->getJson('/api/v1/transactions?payment_method=maya')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/transactions?customer_id={$maria->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/transactions?order_id={$pedroOrder->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/transactions?date_from='.now()->toDateString())->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/transactions?date_to='.now()->subDay()->toDateString())->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/transactions?search=SC-2026-00123')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer.name', 'Pedro Reyes');
        $this->getJson('/api/v1/transactions?search=maria')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/transactions?search=1,299.00')->assertOk()->assertJsonCount(1, 'data');
        $reference = Transaction::where('type', 'refund')->value('reference');
        $this->getJson("/api/v1/transactions?search={$reference}")->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame(['199.00', '799.00', '1299.00'], collect($this->getJson('/api/v1/transactions?sort=amount_asc')->json('data'))->pluck('amount')->all());
        $this->assertSame('1299.00', $this->getJson('/api/v1/transactions?sort=oldest')->json('data.0.amount'));

        $this->getJson('/api/v1/transactions?type=adjustment&sort=bogus&payment_method=bitcoin')->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'sort', 'payment_method']);
    }

    /**
     * @return array{0: Merchant, 1: Order}
     */
    protected function merchantWithOrder(float $total): array
    {
        $merchant = Merchant::factory()->create();
        $order = Order::factory()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => Customer::factory()->create(['merchant_id' => $merchant->id])->id,
            'status' => OrderStatus::Pending,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'total_amount' => $total,
        ]);

        return [$merchant, $order];
    }

    protected function adminWithRole(string $roleSlug): User
    {
        $admin = User::factory()->admin()->create();
        $admin->adminRoles()->sync([AdminRole::where('slug', $roleSlug)->firstOrFail()->id]);

        return $admin->fresh(['adminRoles.permissions', 'adminPermissions']);
    }
}
