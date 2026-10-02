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
use App\Models\Refund;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RefundManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Merchant $merchant;

    protected Customer $customer;

    protected Product $headphones;

    protected Product $cable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::factory()->create();
        $this->customer = Customer::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Maria Santos']);
        $this->headphones = Product::factory()->create(['merchant_id' => $this->merchant->id, 'status' => ProductStatus::Active, 'name' => 'Wireless Headphones', 'price' => 200, 'stock_quantity' => 10]);
        $this->cable = Product::factory()->create(['merchant_id' => $this->merchant->id, 'status' => ProductStatus::Active, 'name' => 'USB-C Cable', 'price' => 400, 'stock_quantity' => 10]);
        Sanctum::actingAs($this->merchant->user);
    }

    public function test_item_refund_request_is_priced_server_side_and_moves_through_approval_processing_and_completion(): void
    {
        [$order, $paymentId] = $this->paidOrder();
        $headphonesItem = $order->items->firstWhere('product_id', $this->headphones->id);

        $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'items' => [['order_item_id' => $headphonesItem->id, 'quantity' => 1]], 'amount' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $refund = $this->postJson('/api/v1/refunds', [
            'order_id' => $order->id, 'customer_id' => $this->customer->id, 'reason' => 'Defective Item', 'notes' => 'Left earcup is silent',
            'items' => [['order_item_id' => $headphonesItem->id, 'quantity' => 2]],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', '400.00')
            ->assertJsonPath('data.payment_id', $paymentId)
            ->assertJsonPath('data.items.0.product_name', 'Wireless Headphones')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.amount', '400.00')
            ->assertJsonPath('data.requested_by.id', $this->merchant->user->id)
            ->assertJsonPath('data.history.0.to_status', 'pending')
            ->json('data');

        $this->getJson("/api/v1/orders/{$order->id}/refundable")->assertOk()
            ->assertJsonPath('data.items.0.refunded_or_reserved_quantity', 2)
            ->assertJsonPath('data.items.0.refundable_quantity', 1)
            ->assertJsonPath('data.refundable_amount', '600.00');

        $this->patchJson("/api/v1/refunds/{$refund['id']}/status", ['status' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->patchJson("/api/v1/refunds/{$refund['id']}/status", ['status' => 'processing', 'payout_reference' => 'GC-REFUND-0032'])->assertOk()
            ->assertJsonPath('data.status', 'processing');
        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(PaymentStatus::Completed, Payment::find($paymentId)->status);

        $this->patchJson("/api/v1/refunds/{$refund['id']}/status", ['status' => 'processed'])->assertOk()
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.payout_reference', 'GC-REFUND-0032')
            ->assertJsonPath('data.payment.status', 'partially_refunded')
            ->assertJsonPath('data.order.payment_status', 'partially_refunded')
            ->assertJsonPath('data.transactions.0.type', 'refund')
            ->assertJsonPath('data.transactions.0.status', 'completed')
            ->assertJsonPath('data.transactions.0.amount', '400.00')
            ->assertJsonPath('data.history.1.to_status', 'approved')
            ->assertJsonPath('data.history.2.to_status', 'processing')
            ->assertJsonPath('data.history.3.from_status', 'processing')
            ->assertJsonPath('data.history.3.to_status', 'processed')
            ->assertJsonPath('data.history.3.user.id', $this->merchant->user->id);

        $this->assertSame(7, $this->headphones->fresh()->stock_quantity, 'Refunds without a return do not restock.');
        $this->patchJson("/api/v1/refunds/{$refund['id']}/status", ['status' => 'cancelled'])->assertUnprocessable();
        $this->patchJson("/api/v1/refunds/{$refund['id']}", ['amount' => 100])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    }

    public function test_return_refund_completes_the_return_and_restocks_returned_items(): void
    {
        [$order, $paymentId] = $this->paidOrder();
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'processing'])->assertOk();
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'completed'])->assertOk();
        $cableItem = $order->items->firstWhere('product_id', $this->cable->id);

        $returnId = $this->postJson('/api/v1/return-requests', [
            'order_id' => $order->id, 'customer_id' => $this->customer->id, 'reason' => 'Damaged on Delivery', 'notes' => 'Connector bent',
            'items' => [['order_item_id' => $cableItem->id, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/refunds', ['return_request_id' => $returnId])
            ->assertUnprocessable()->assertJsonValidationErrors(['return_request_id']);
        $this->patchJson("/api/v1/return-requests/{$returnId}", ['status' => 'approved'])->assertOk();

        $refundId = $this->postJson('/api/v1/refunds', ['return_request_id' => $returnId])->assertCreated()
            ->assertJsonPath('data.amount', '400.00')
            ->assertJsonPath('data.reason', 'Damaged on Delivery')
            ->assertJsonPath('data.return_request.id', $returnId)
            ->assertJsonPath('data.items.0.order_item_id', $cableItem->id)
            ->json('data.id');
        $this->postJson('/api/v1/refunds', ['return_request_id' => $returnId])
            ->assertUnprocessable()->assertJsonValidationErrors(['return_request_id']);
        $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'items' => [['order_item_id' => $cableItem->id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity']);

        $this->assertSame(9, $this->cable->fresh()->stock_quantity);
        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'processed'])->assertOk()
            ->assertJsonPath('data.return_request.status', 'processed')
            ->assertJsonPath('data.order.payment_status', 'partially_refunded');

        $this->assertSame(10, $this->cable->fresh()->stock_quantity);
        $this->assertDatabaseHas('return_requests', ['id' => $returnId, 'status' => 'processed', 'refund_id' => $refundId]);
        $this->getJson('/api/v1/inventory/logs?type=return')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/transactions?refund_id={$refundId}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '400.00');
        $this->assertSame(PaymentStatus::PartiallyRefunded, Payment::find($paymentId)->status);
    }

    public function test_full_refund_with_order_cancellation_restores_stock_and_marks_everything_refunded(): void
    {
        [$order, $paymentId] = $this->paidOrder();

        $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'amount' => 999, 'cancel_order' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['cancel_order']);

        $refundId = $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'full_refund' => true, 'cancel_order' => true, 'reason' => 'Changed Mind'])
            ->assertCreated()->assertJsonPath('data.amount', '1000.00')->assertJsonPath('data.cancel_order', true)->json('data.id');
        $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'full_refund' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'processed'])->assertOk()
            ->assertJsonPath('data.order.status', 'cancelled')
            ->assertJsonPath('data.order.payment_status', 'refunded')
            ->assertJsonPath('data.order.inventory_restored', true)
            ->assertJsonPath('data.payment.status', 'refunded');

        $this->assertSame([10, 10], [$this->headphones->fresh()->stock_quantity, $this->cable->fresh()->stock_quantity]);
        $this->assertSame(PaymentStatus::Refunded, Payment::find($paymentId)->status);
        $this->getJson("/api/v1/orders/{$order->id}/refundable")->assertOk()->assertJsonPath('data.refundable_amount', '0.00');
    }

    public function test_rejected_and_cancelled_refunds_release_their_reservation(): void
    {
        [$order] = $this->paidOrder();
        $item = $order->items->firstWhere('product_id', $this->headphones->id);
        $request = fn () => $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'items' => [['order_item_id' => $item->id, 'quantity' => 3]]]);

        $first = $request()->assertCreated()->json('data.id');
        $request()->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity']);

        $this->patchJson("/api/v1/refunds/{$first}/status", ['status' => 'rejected', 'notes' => 'Outside return policy'])->assertOk()
            ->assertJsonPath('data.reviewed_by.id', $this->merchant->user->id);
        $this->patchJson("/api/v1/refunds/{$first}/status", ['status' => 'processed'])->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $second = $request()->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/refunds/{$second}/status", ['status' => 'cancelled'])->assertOk();
        $request()->assertCreated();

        $this->assertDatabaseCount('transactions', 1);
        $this->deleteJson("/api/v1/refunds/{$first}")->assertNoContent();
    }

    public function test_failed_payout_is_recorded_and_can_be_retried_without_duplicating_the_ledger(): void
    {
        [$order, $paymentId] = $this->paidOrder();
        $refundId = $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'amount' => 250, 'reason' => 'Not as Described'])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'failed'])->assertUnprocessable();
        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'processing'])->assertOk();
        $this->deleteJson("/api/v1/refunds/{$refundId}")->assertStatus(409);
        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'failed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['failure_reason']);
        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'failed', 'failure_reason' => 'GCash account closed'])->assertOk()
            ->assertJsonPath('data.failure_reason', 'GCash account closed')
            ->assertJsonPath('data.transactions.0.status', 'failed');
        $this->assertSame(PaymentStatus::Completed, Payment::find($paymentId)->status);

        $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'amount' => 750.01])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'processing', 'payout_reference' => 'BPI-RETRY-1'])->assertOk();
        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'processed'])->assertOk()
            ->assertJsonPath('data.failure_reason', null)
            ->assertJsonCount(1, 'data.transactions')
            ->assertJsonPath('data.transactions.0.status', 'completed')
            ->assertJsonPath('data.payment.status', 'partially_refunded');
        $this->assertSame(1, Transaction::where('refund_id', $refundId)->count());
    }

    public function test_duplicate_requests_and_completions_are_idempotent(): void
    {
        [$order, $paymentId] = $this->paidOrder();
        $payload = ['order_id' => $order->id, 'amount' => 100, 'reason' => 'Wrong Item'];

        $first = $this->postJson('/api/v1/refunds', $payload, ['Idempotency-Key' => 'refund-req-001'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/refunds', $payload, ['Idempotency-Key' => 'refund-req-001'])->assertOk()->assertJsonPath('data.id', $first);
        $this->assertSame(1, Refund::count());

        $this->patchJson("/api/v1/refunds/{$first}/status", ['status' => 'processed'])->assertOk();
        $this->patchJson("/api/v1/refunds/{$first}/status", ['status' => 'processed'])->assertOk();
        $this->assertSame(1, Transaction::where('refund_id', $first)->count());
        $this->assertSame(1, Refund::find($first)->histories()->where('to_status', 'processed')->count());
        $this->assertEquals(100, (float) Payment::find($paymentId)->refunds()->where('status', 'processed')->sum('amount'));

        $this->postJson('/api/v1/refunds', $payload, ['Idempotency-Key' => str_repeat('x', 101)])
            ->assertUnprocessable()->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_invalid_and_unauthorized_refunds_are_rejected(): void
    {
        $this->seed(AdminAuthorizationSeeder::class);
        [$order, $paymentId] = $this->paidOrder();
        $otherOrder = Order::factory()->create(['merchant_id' => $this->merchant->id, 'customer_id' => $this->customer->id, 'status' => OrderStatus::Pending, 'total_amount' => 50]);
        $pendingPayment = Payment::factory()->pending()->create(['merchant_id' => $this->merchant->id, 'order_id' => $otherOrder->id, 'amount' => 50]);
        $stranger = Customer::factory()->create(['merchant_id' => $this->merchant->id]);
        $foreignItem = $otherOrder->items()->create(['product_name' => 'Other', 'quantity' => 1, 'unit_price' => 50, 'total_price' => 50]);

        $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'customer_id' => $stranger->id, 'full_refund' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_id']);
        $this->postJson('/api/v1/refunds', ['payment_id' => $pendingPayment->id, 'amount' => 10])
            ->assertUnprocessable()->assertJsonValidationErrors(['payment_id']);
        $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'items' => [['order_item_id' => $foreignItem->id, 'quantity' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['items.0.order_item_id']);
        $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'amount' => 1000.01])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/v1/refunds', ['order_id' => $order->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'amount' => 10, 'status' => 'approved'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);

        $refundId = $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'amount' => 10])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'pending'])->assertUnprocessable();

        Sanctum::actingAs(Merchant::factory()->create()->user);
        $this->getJson("/api/v1/refunds/{$refundId}")->assertNotFound();
        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'approved'])->assertNotFound();
        $this->getJson("/api/v1/orders/{$order->id}/refundable")->assertNotFound();
        $this->postJson('/api/v1/refunds', ['payment_id' => $paymentId, 'amount' => 10])->assertNotFound();
        $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'full_refund' => true])->assertNotFound();

        Sanctum::actingAs($this->adminWithRole(AdminRoleRegistry::CUSTOMER_SUPPORT), ['admin'], 'sanctum');
        $this->getJson("/api/admin/refunds/{$refundId}")->assertOk();
        $this->patchJson("/api/admin/refunds/{$refundId}/status", ['status' => 'approved'])->assertForbidden();
        $this->patchJson("/api/v1/refunds/{$refundId}/status", ['status' => 'approved'])->assertForbidden();

        $orderManager = $this->adminWithRole(AdminRoleRegistry::ORDER_MANAGER);
        Sanctum::actingAs($orderManager, ['admin'], 'sanctum');
        $this->patchJson("/api/admin/refunds/{$refundId}/status", ['status' => 'approved'])->assertOk()
            ->assertJsonPath('data.reviewed_by.id', $orderManager->id);
    }

    public function test_refund_list_filters_searches_and_paginates(): void
    {
        [$order, $paymentId] = $this->paidOrder('maya');
        $otherCustomer = Customer::factory()->create(['merchant_id' => $this->merchant->id, 'name' => 'Pedro Reyes']);
        $otherOrder = Order::factory()->create(['merchant_id' => $this->merchant->id, 'customer_id' => $otherCustomer->id, 'status' => OrderStatus::Pending, 'total_amount' => 300]);
        $this->postJson('/api/v1/payments', ['order_id' => $otherOrder->id, 'method' => 'cash', 'status' => 'completed', 'amount' => 300])->assertCreated();

        $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'amount' => 100, 'reason' => 'Defective Item'])->assertCreated();
        $processed = $this->postJson('/api/v1/refunds', ['order_id' => $order->id, 'amount' => 50, 'reason' => 'Wrong Item'])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/refunds/{$processed}/status", ['status' => 'processed'])->assertOk();
        $this->postJson('/api/v1/refunds', ['order_id' => $otherOrder->id, 'amount' => 30, 'reason' => 'Changed Mind'])->assertCreated();

        $this->getJson('/api/v1/refunds?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 2);
        $this->getJson("/api/v1/refunds?customer_id={$otherCustomer->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/refunds?payment_method=maya')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/refunds?status=processed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $processed);
        $this->getJson("/api/v1/refunds?order_id={$order->id}&reason=Defective")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/refunds?search=pedro')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/refunds?search={$order->order_number}")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['100.00', '50.00', '30.00'], collect($this->getJson('/api/v1/refunds?sort=amount_desc')->json('data'))->pluck('amount')->all());
        $this->getJson('/api/v1/refunds?date_from='.now()->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/refunds?payment_method=crypto')->assertUnprocessable()->assertJsonValidationErrors(['payment_method']);

        $this->getJson('/api/v1/refunds/summary')->assertOk()
            ->assertJsonPath('data.total_refunds', 3)
            ->assertJsonPath('data.by_status.pending', 2)
            ->assertJsonPath('data.by_status.processed', 1)
            ->assertJsonPath('data.refunded_amount', '50.00');
    }

    /**
     * An order for 3 × headphones (₱200) and 1 × cable (₱400), paid in full and verified.
     *
     * @return array{0: Order, 1: int}
     */
    protected function paidOrder(string $method = 'gcash'): array
    {
        $orderId = $this->postJson('/api/v1/orders', ['customer_id' => $this->customer->id, 'items' => [
            ['product_id' => $this->headphones->id, 'quantity' => 3],
            ['product_id' => $this->cable->id, 'quantity' => 1],
        ]])->assertCreated()->json('data.id');

        $paymentId = $this->postJson('/api/v1/payments', ['order_id' => $orderId, 'method' => $method, 'amount' => 1000])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/payments/{$paymentId}/status", ['status' => 'completed'])->assertOk();

        $order = Order::with('items')->findOrFail($orderId);
        $this->assertSame(OrderPaymentStatus::Paid, $order->payment_status);

        return [$order, $paymentId];
    }

    protected function adminWithRole(string $roleSlug): User
    {
        $admin = User::factory()->admin()->create();
        $admin->adminRoles()->sync([AdminRole::where('slug', $roleSlug)->firstOrFail()->id]);

        return $admin->fresh(['adminRoles.permissions', 'adminPermissions']);
    }
}
