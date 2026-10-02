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
use App\Models\User;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_payment_is_only_completed_by_verification_which_records_a_transaction(): void
    {
        [$merchant, $order] = $this->merchantWithOrder(500);
        Sanctum::actingAs($merchant->user);

        $payment = $this->postJson('/api/v1/payments', [
            'order_id' => $order->id, 'method' => 'gcash', 'amount' => 500,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.method', 'gcash')
            ->assertJsonPath('data.gateway', 'manual')
            ->assertJsonPath('data.currency', 'PHP')
            ->assertJsonPath('data.paid_at', null)
            ->assertJsonPath('meta.order_balance.outstanding_balance', '500.00')
            ->assertJsonPath('meta.order_balance.pending_amount', '500.00')
            ->json('data');

        $this->assertMatchesRegularExpression('/^PAY-\d{8}-[A-Z0-9]{8}$/', $payment['reference']);
        $this->assertNotNull($payment['expires_at']);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);

        $this->patchJson("/api/v1/payments/{$payment['id']}", ['status' => 'completed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->patchJson("/api/v1/payments/{$payment['id']}/status", [
            'status' => 'completed', 'gateway_reference' => 'GC12345678', 'notes' => 'Verified in GCash merchant app',
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.gateway_reference', 'GC12345678')
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.verified_by.id', $merchant->user->id)
            ->assertJsonPath('data.transactions.0.type', 'payment')
            ->assertJsonPath('data.transactions.0.status', 'completed')
            ->assertJsonPath('data.transactions.0.amount', '500.00')
            ->assertJsonPath('data.order.payment_status', 'paid')
            ->assertJsonPath('meta.order_balance.outstanding_balance', '0.00');

        $this->patchJson("/api/v1/payments/{$payment['id']}/status", ['status' => 'cancelled'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->patchJson("/api/v1/payments/{$payment['id']}/status", ['status' => 'completed'])->assertOk();
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_partial_payments_track_the_outstanding_balance_and_reject_overpayment(): void
    {
        [$merchant, $order] = $this->merchantWithOrder(1000);
        Sanctum::actingAs($merchant->user);

        $this->postJson('/api/v1/payments', [
            'order_id' => $order->id, 'method' => 'bank_transfer', 'status' => 'completed', 'amount' => 400,
            'reference' => 'BPI-0001', 'paid_at' => now()->subHour()->toIso8601String(),
        ])->assertCreated()->assertJsonPath('data.order.payment_status', 'partially_paid');

        $this->postJson('/api/v1/payments', [
            'order_id' => $order->id, 'method' => 'cash', 'status' => 'completed', 'amount' => 600.01,
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $pendingId = $this->postJson('/api/v1/payments', ['order_id' => $order->id, 'method' => 'cash', 'amount' => 600])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/payments', [
            'order_id' => $order->id, 'method' => 'cash', 'status' => 'completed', 'amount' => 600,
        ])->assertCreated();

        $this->patchJson("/api/v1/payments/{$pendingId}/status", ['status' => 'completed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->getJson("/api/v1/orders/{$order->id}/payment-balance")->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.amount_paid', '1000.00')
            ->assertJsonPath('data.pending_amount', '600.00')
            ->assertJsonPath('data.outstanding_balance', '0.00')
            ->assertJsonCount(3, 'data.payments');
    }

    public function test_payments_can_fail_be_cancelled_and_expire(): void
    {
        [$merchant, $order] = $this->merchantWithOrder(300);
        Sanctum::actingAs($merchant->user);
        $create = fn (array $extra = []) => $this->postJson('/api/v1/payments', ['order_id' => $order->id, 'method' => 'maya', 'amount' => 100, ...$extra])
            ->assertCreated()->json('data');

        $failing = $create();
        $this->patchJson("/api/v1/payments/{$failing['id']}/status", ['status' => 'failed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->patchJson("/api/v1/payments/{$failing['id']}/status", ['status' => 'failed', 'reason' => 'Insufficient balance'])
            ->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.failure_reason', 'Insufficient balance');
        $this->patchJson("/api/v1/payments/{$failing['id']}/status", ['status' => 'completed'])->assertUnprocessable();

        $cancelled = $create();
        $this->patchJson("/api/v1/payments/{$cancelled['id']}/status", ['status' => 'cancelled', 'reason' => 'Customer changed method'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $expiring = $create();
        $cod = $create(['method' => 'cod']);
        $this->assertNull($cod['expires_at']);

        $this->travel(config('payments.pending_expiry_minutes') + 1)->minutes();
        $this->patchJson("/api/v1/payments/{$expiring['id']}/status", ['status' => 'completed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->artisan('payments:expire')->expectsOutput('Expired 1 pending payment(s).')->assertSuccessful();

        $this->assertSame(PaymentStatus::Expired, Payment::find($expiring['id'])->status);
        $this->assertSame(PaymentStatus::Pending, Payment::find($cod['id'])->status);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseHas('transactions', ['payment_id' => $failing['id'], 'type' => 'payment', 'status' => 'failed', 'source_key' => 'payment:'.$failing['id']]);
        $this->assertSame(OrderPaymentStatus::Unpaid, $order->fresh()->payment_status);

        $this->deleteJson("/api/v1/payments/{$expiring['id']}")->assertNoContent();
    }

    public function test_collected_payments_cannot_be_rewritten_or_deleted(): void
    {
        [$merchant, $order] = $this->merchantWithOrder(200);
        Sanctum::actingAs($merchant->user);
        $paymentId = $this->postJson('/api/v1/payments', [
            'order_id' => $order->id, 'method' => 'card', 'status' => 'completed', 'amount' => 200,
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/payments/{$paymentId}", ['amount' => 150])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->patchJson("/api/v1/payments/{$paymentId}", ['notes' => 'Visa ending 4242', 'gateway_reference' => 'ch_3N8v'])
            ->assertOk()->assertJsonPath('data.notes', 'Visa ending 4242')->assertJsonPath('data.amount', '200.00');
        $this->deleteJson("/api/v1/payments/{$paymentId}")->assertStatus(409);

        $cancelledOrder = Order::factory()->create(['merchant_id' => $merchant->id, 'status' => OrderStatus::Cancelled, 'total_amount' => 50]);
        $this->postJson('/api/v1/payments', ['order_id' => $cancelledOrder->id, 'method' => 'cash', 'amount' => 50])
            ->assertUnprocessable()->assertJsonValidationErrors(['order_id']);
    }

    public function test_payment_proof_attachments_and_sensitive_metadata_are_protected(): void
    {
        Storage::fake('local');
        [$merchant, $order] = $this->merchantWithOrder(250);
        Sanctum::actingAs($merchant->user);

        $payment = $this->post('/api/v1/payments', [
            'order_id' => $order->id, 'method' => 'card', 'status' => 'completed', 'amount' => 250,
            'attachments' => [UploadedFile::fake()->create('visa-receipt.pdf', 200, 'application/pdf')],
            'metadata' => ['terminal' => 'POS-1', 'card_number' => '4111111111111111', 'cvv' => '123'],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.attachments.0.name', 'visa-receipt.pdf')
            ->assertJsonMissingPath('data.attachments.0.path')
            ->assertJsonPath('data.metadata.card_number', '[REDACTED]')
            ->json('data');

        $stored = DB::table('payments')->where('id', $payment['id'])->value('metadata');
        $this->assertStringNotContainsString('4111111111111111', $stored);
        $this->assertStringNotContainsString('"123"', $stored);
        $this->assertStringContainsString('POS-1', $stored);

        $this->get("/api/v1/payments/{$payment['id']}/attachments/0")->assertOk()->assertDownload('visa-receipt.pdf');
        $this->getJson("/api/v1/payments/{$payment['id']}/attachments/1")->assertNotFound();

        Sanctum::actingAs(Merchant::factory()->create()->user);
        $this->getJson("/api/v1/payments/{$payment['id']}/attachments/0")->assertNotFound();
    }

    public function test_payments_list_filters_searches_sorts_and_summarizes(): void
    {
        [$merchant, $order] = $this->merchantWithOrder(5000, ['order_number' => 'SC-2026-00125'], ['name' => 'Juan Dela Cruz']);
        Sanctum::actingAs($merchant->user);
        $this->postJson('/api/v1/payments', ['order_id' => $order->id, 'method' => 'card', 'status' => 'completed', 'amount' => 2499, 'reference' => 'TXN789456'])->assertCreated();
        $this->postJson('/api/v1/payments', ['order_id' => $order->id, 'method' => 'gcash', 'status' => 'failed', 'amount' => 599, 'failure_reason' => 'Timeout'])->assertCreated();
        $this->postJson('/api/v1/payments', ['order_id' => $order->id, 'method' => 'cod', 'amount' => 1099])->assertCreated();
        Payment::factory()->create(['merchant_id' => Merchant::factory()->create()->id, 'amount' => 9999]);

        $this->getJson('/api/v1/payments')->assertOk()->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/payments?search=dela cruz')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/payments?search=SC-2026-00125&status=completed')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'TXN789456')
            ->assertJsonPath('data.0.order.customer.name', 'Juan Dela Cruz');
        $this->getJson('/api/v1/payments?method=cod')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(['2499.00', '1099.00', '599.00'], collect($this->getJson('/api/v1/payments?sort=amount_desc')->json('data'))->pluck('amount')->all());
        $this->getJson('/api/v1/payments?status=bogus')->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->getJson('/api/v1/payments/summary')->assertOk()
            ->assertJsonPath('data.total_payments', 3)
            ->assertJsonPath('data.completed_payments', 1)
            ->assertJsonPath('data.by_status.pending', 1)
            ->assertJsonPath('data.by_status.failed', 1)
            ->assertJsonPath('data.collected_amount', '2499.00')
            ->assertJsonPath('data.collected_change_percent', null);
    }

    public function test_refund_requests_are_approved_then_processed_against_the_refundable_balance(): void
    {
        [$merchant, $order] = $this->merchantWithOrder(1000);
        Sanctum::actingAs($merchant->user);
        $paymentId = $this->postJson('/api/v1/payments', ['order_id' => $order->id, 'method' => 'gcash', 'status' => 'completed', 'amount' => 1000])
            ->assertCreated()->json('data.id');
        $pendingPaymentId = $this->postJson('/api/v1/payments', ['method' => 'gcash', 'amount' => 50])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/refunds', ['payment_id' => $pendingPaymentId, 'amount' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors(['payment_id']);

        $refund = $this->postJson('/api/v1/refunds', [
            'payment_id' => $paymentId, 'amount' => 700, 'reason' => 'Defective Item', 'notes' => 'Product not working',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.order_id', $order->id)
            ->assertJsonPath('data.payment.method', 'gcash')
            ->json('data');
        $this->assertMatchesRegularExpression('/^RF-\d{8}-[A-Z0-9]{8}$/', $refund['reference']);

        $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 400])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->patchJson("/api/v1/refunds/{$refund['id']}", ['status' => 'processed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $this->patchJson("/api/v1/refunds/{$refund['id']}/status", ['status' => 'approved'])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by.id', $merchant->user->id);
        $this->assertSame(PaymentStatus::Completed, Payment::find($paymentId)->status);

        $this->patchJson("/api/v1/refunds/{$refund['id']}/status", ['status' => 'processed'])->assertOk()
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.order.payment_status', 'partially_refunded');
        $this->assertSame(PaymentStatus::PartiallyRefunded, Payment::find($paymentId)->status);
        $this->assertDatabaseHas('transactions', ['refund_id' => $refund['id'], 'type' => 'refund', 'status' => 'completed', 'amount' => 700]);
        $this->patchJson("/api/v1/refunds/{$refund['id']}/status", ['status' => 'rejected'])->assertUnprocessable();

        $rejected = $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 300])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/refunds/{$rejected}/status", ['status' => 'rejected', 'notes' => 'Outside policy'])->assertOk();
        $this->patchJson("/api/v1/refunds/{$rejected}/status", ['status' => 'processed'])->assertUnprocessable();

        $this->getJson('/api/v1/refunds?status=processed&search=Defective')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/refunds?reason=Defective')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/refunds/summary')->assertOk()
            ->assertJsonPath('data.total_refunds', 2)
            ->assertJsonPath('data.by_status.processed', 1)
            ->assertJsonPath('data.by_status.rejected', 1)
            ->assertJsonPath('data.refunded_amount', '700.00');

        $this->deleteJson("/api/v1/refunds/{$refund['id']}")->assertStatus(409);
        $this->deleteJson("/api/v1/refunds/{$rejected}")->assertNoContent();
        $this->assertDatabaseHas('transactions', ['type' => 'refund', 'status' => 'completed', 'amount' => 700]);
        $this->assertSame(PaymentStatus::PartiallyRefunded, Payment::find($paymentId)->status);
        $this->assertSame(OrderPaymentStatus::PartiallyRefunded, $order->fresh()->payment_status);
    }

    public function test_payment_and_refund_access_is_scoped_and_permission_checked(): void
    {
        $this->seed(AdminAuthorizationSeeder::class);
        [$merchant, $order] = $this->merchantWithOrder(100);
        $payment = Payment::factory()->pending()->create(['merchant_id' => $merchant->id, 'order_id' => $order->id, 'amount' => 100]);

        Sanctum::actingAs(Merchant::factory()->create()->user);
        $this->getJson("/api/v1/payments/{$payment->id}")->assertNotFound();
        $this->patchJson("/api/v1/payments/{$payment->id}/status", ['status' => 'completed'])->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/payment-balance")->assertNotFound();
        $this->getJson('/api/v1/payments')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs($this->adminWithRole(AdminRoleRegistry::PRODUCT_MANAGER), ['admin'], 'sanctum');
        $this->getJson('/api/v1/payments')->assertForbidden();

        Sanctum::actingAs($this->adminWithRole(AdminRoleRegistry::CUSTOMER_SUPPORT), ['admin'], 'sanctum');
        $this->getJson("/api/admin/payments?merchant_id={$merchant->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/payments/summary')->assertOk();
        $this->patchJson("/api/admin/payments/{$payment->id}/status", ['status' => 'completed'])->assertForbidden();
        $this->patchJson("/api/v1/payments/{$payment->id}/status", ['status' => 'completed'])->assertForbidden();

        $orderManager = $this->adminWithRole(AdminRoleRegistry::ORDER_MANAGER);
        Sanctum::actingAs($orderManager, ['admin'], 'sanctum');
        $this->patchJson("/api/admin/payments/{$payment->id}/status", ['status' => 'completed'])->assertOk()
            ->assertJsonPath('data.verified_by.id', $orderManager->id);
        $this->assertSame(OrderPaymentStatus::Paid, $order->fresh()->payment_status);
    }

    public function test_full_flow_from_order_payment_and_verification_to_refund_cancellation_and_restock(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id, 'status' => ProductStatus::Active, 'price' => 250, 'stock_quantity' => 10,
        ]);
        Sanctum::actingAs($merchant->user);

        $orderId = $this->postJson('/api/v1/orders', ['customer_id' => $customer->id, 'items' => [
            ['product_id' => $product->id, 'quantity' => 4],
        ]])->assertCreated()->json('data.id');
        $this->assertSame(6, $product->fresh()->stock_quantity);

        $paymentId = $this->postJson('/api/v1/payments', ['order_id' => $orderId, 'method' => 'bank_transfer', 'amount' => 1000, 'reference' => 'BPI20260811'])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'processing'])->assertOk();
        $this->patchJson("/api/v1/payments/{$paymentId}/status", ['status' => 'completed', 'gateway_reference' => 'BPI-TRX-1'])->assertOk()
            ->assertJsonPath('data.order.payment_status', 'paid');

        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'cancelled'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $partialId = $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 400])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/refunds/{$partialId}/status", ['status' => 'processed', 'cancel_order' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['cancel_order']);
        $this->assertSame(PaymentStatus::Completed, Payment::find($paymentId)->status);

        $this->patchJson("/api/v1/refunds/{$partialId}/status", ['status' => 'processed'])->assertOk();
        $restId = $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 600, 'reason' => 'Order cancelled by customer'])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/refunds/{$restId}/status", ['status' => 'processed', 'cancel_order' => true])->assertOk()
            ->assertJsonPath('data.order.status', 'cancelled')
            ->assertJsonPath('data.order.payment_status', 'refunded')
            ->assertJsonPath('data.order.inventory_restored', true);

        $this->assertSame(10, $product->fresh()->stock_quantity);
        $this->assertSame(PaymentStatus::Refunded, Payment::find($paymentId)->status);
        $this->assertSame(
            [['payment', 'completed', '1000.00'], ['refund', 'completed', '400.00'], ['refund', 'completed', '600.00']],
            collect($this->getJson("/api/v1/transactions?payment_id={$paymentId}")->json('data'))
                ->sortBy('id')->map(fn (array $tx) => [$tx['type'], $tx['status'], $tx['amount']])->values()->all(),
        );
        $this->getJson('/api/v1/inventory/logs?type=cancellation')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/orders/{$orderId}/payment-balance")->assertOk()
            ->assertJsonPath('data.amount_paid', '1000.00')
            ->assertJsonPath('data.amount_refunded', '1000.00')
            ->assertJsonPath('data.net_paid', '0.00');
    }

    /**
     * @param  array<string, mixed>  $orderAttributes
     * @param  array<string, mixed>  $customerAttributes
     * @return array{0: Merchant, 1: Order}
     */
    protected function merchantWithOrder(float $total, array $orderAttributes = [], array $customerAttributes = []): array
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id, ...$customerAttributes]);
        $order = Order::factory()->create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'status' => OrderStatus::Pending,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'total_amount' => $total,
            ...$orderAttributes,
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
