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
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReturnRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_return_requires_a_matching_processed_refund_and_restocks_only_once(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id, 'price' => 100, 'stock_quantity' => 10, 'status' => ProductStatus::Active,
        ]);
        Sanctum::actingAs($merchant->user);

        $orderId = $this->postJson('/api/v1/orders', [
            'customer_id' => $customer->id, 'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])->assertCreated()->json('data.id');
        $itemId = Order::findOrFail($orderId)->items()->firstOrFail()->id;
        $this->assertEquals(6, $product->fresh()->stock_quantity);
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'processing'])->assertOk();
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'completed'])->assertUnprocessable();
        $paymentId = $this->postJson('/api/v1/payments', [
            'order_id' => $orderId, 'reference' => 'pay-return-1',
            'gateway' => 'manual', 'status' => PaymentStatus::Completed->value, 'amount' => 400,
        ])->assertCreated()->json('data.id');
        $payment = Payment::findOrFail($paymentId);
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'completed'])->assertOk();

        Order::findOrFail($orderId)->update(['payment_status' => OrderPaymentStatus::Unpaid]);
        $this->postJson('/api/v1/return-requests', [
            'order_id' => $orderId, 'customer_id' => $customer->id, 'reason' => 'Damaged',
            'items' => [['order_item_id' => $itemId, 'quantity' => 1]],
        ])->assertUnprocessable();

        Order::findOrFail($orderId)->update(['payment_status' => OrderPaymentStatus::Paid]);

        $payload = [
            'order_id' => $orderId, 'customer_id' => $customer->id, 'reason' => 'Damaged',
            'items' => [['order_item_id' => $itemId, 'quantity' => 2]],
        ];
        $returnId = $this->postJson('/api/v1/return-requests', $payload)->assertCreated()
            ->assertJsonPath('data.amount', '200.00')->json('data.id');
        $payload['items'][0]['quantity'] = 3;
        $this->postJson('/api/v1/return-requests', $payload)->assertUnprocessable();
        $this->patchJson("/api/v1/return-requests/{$returnId}", ['status' => 'processed', 'refund_id' => 999])
            ->assertUnprocessable();
        $this->patchJson("/api/v1/return-requests/{$returnId}", ['status' => 'approved'])->assertOk();

        $refund = Refund::create([
            'merchant_id' => $merchant->id, 'order_id' => $orderId, 'payment_id' => $payment->id,
            'reference' => 'return-refund-1', 'amount' => 200, 'status' => RefundStatus::Processed,
        ]);
        $this->patchJson("/api/v1/return-requests/{$returnId}", [
            'status' => 'processed', 'refund_id' => $refund->id,
        ])->assertOk()->assertJsonPath('data.refund_id', $refund->id);
        $this->patchJson("/api/v1/return-requests/{$returnId}", [
            'status' => 'processed', 'refund_id' => $refund->id,
        ])->assertOk();
        $this->assertEquals(8, $product->fresh()->stock_quantity);
        $this->patchJson("/api/v1/refunds/{$refund->id}", ['amount' => 100])->assertStatus(409);
        $this->deleteJson("/api/v1/refunds/{$refund->id}")->assertStatus(409);
    }

    public function test_return_access_and_quantities_are_scoped_to_merchant_and_order_customer(): void
    {
        $merchant = Merchant::factory()->create();
        $other = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $order = Order::factory()->create([
            'merchant_id' => $merchant->id, 'customer_id' => $customer->id, 'status' => OrderStatus::Completed,
            'payment_status' => OrderPaymentStatus::Paid, 'ordered_at' => now(),
        ]);
        $item = $order->items()->create([
            'product_name' => 'Example', 'quantity' => 2, 'unit_price' => 25, 'total_price' => 50,
        ]);
        $payload = [
            'order_id' => $order->id, 'customer_id' => $customer->id, 'reason' => 'Wrong size',
            'items' => [['order_item_id' => $item->id, 'quantity' => 3]],
        ];
        Sanctum::actingAs($merchant->user);
        $this->postJson('/api/v1/return-requests', $payload)->assertUnprocessable();
        $payload['items'][0]['quantity'] = 1;
        $payload['customer_id'] = Customer::factory()->create(['merchant_id' => $merchant->id])->id;
        $this->postJson('/api/v1/return-requests', $payload)->assertUnprocessable();
        $payload['customer_id'] = $customer->id;
        $returnId = $this->postJson('/api/v1/return-requests', $payload)->assertCreated()->json('data.id');
        Sanctum::actingAs($other->user);
        $this->getJson("/api/v1/return-requests/{$returnId}")->assertNotFound();
        $this->patchJson("/api/v1/return-requests/{$returnId}", ['status' => 'approved'])->assertNotFound();
        $this->postJson('/api/v1/return-requests', $payload)->assertNotFound();
    }

    public function test_variant_stock_and_order_finances_are_server_owned_and_cancellation_restores_stock(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id, 'address' => 'Shipping snapshot']);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id, 'price' => 90, 'stock_quantity' => 10, 'status' => ProductStatus::Active,
        ]);
        $variant = $product->variants()->create(['sku' => 'VAR-RETURN-1', 'price' => 125, 'stock' => 5]);
        Sanctum::actingAs($merchant->user);
        $payload = [
            'customer_id' => $customer->id, 'items' => [[
                'product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 2,
            ]],
        ];
        $this->postJson('/api/v1/orders', $payload + ['payment_status' => 'paid'])->assertUnprocessable();
        $this->postJson('/api/v1/orders', array_replace($payload, ['items' => [[
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'quantity' => 2, 'unit_price' => 1,
        ]]]))->assertUnprocessable();
        $id = $this->postJson('/api/v1/orders', $payload)->assertCreated()
            ->assertJsonPath('data.subtotal', '250.00')
            ->assertJsonPath('data.shipping_address', 'Shipping snapshot')
            ->assertJsonPath('data.items.0.product_variant_id', $variant->id)->json('data.id');
        $this->assertEquals(3, $variant->fresh()->stock);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
        $this->patchJson("/api/v1/orders/{$id}", ['items' => $payload['items']])->assertUnprocessable();
        $this->patchJson("/api/v1/orders/{$id}", ['payment_status' => 'paid'])->assertUnprocessable();
        $this->deleteJson("/api/v1/orders/{$id}")->assertStatus(409);
        $this->getJson('/api/v1/orders?status=pending')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->patchJson("/api/v1/orders/{$id}/status", ['status' => 'cancelled'])->assertOk();
        $this->patchJson("/api/v1/orders/{$id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertEquals(5, $variant->fresh()->stock);
    }
}
