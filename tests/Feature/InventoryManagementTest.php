<?php

namespace Tests\Feature;

use App\Admin\AdminRoleRegistry;
use App\Enums\ProductStatus;
use App\Models\AdminRole;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\AdminAuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_lists_product_and_variant_stock_with_reserved_quantities_from_open_orders(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $category = Category::factory()->create(['merchant_id' => $merchant->id, 'name' => 'Electronics']);
        $headphones = $this->activeProduct($merchant, [
            'name' => 'Wireless Headphones', 'sku' => 'WH-001', 'category_id' => $category->id,
            'stock_quantity' => 25, 'low_stock_threshold' => 10, 'cost_price' => 300,
        ]);
        $shoes = $this->activeProduct($merchant, ['name' => 'Running Shoes', 'sku' => 'RS-000', 'stock_quantity' => 0]);
        $variant = $shoes->variants()->create(['sku' => 'RS-002', 'color' => 'Green', 'size' => '9', 'price' => 120, 'stock' => 18]);
        $this->activeProduct(Merchant::factory()->create(), ['sku' => 'OTHER-1']);

        Sanctum::actingAs($merchant->user);
        $this->postJson('/api/v1/orders', ['customer_id' => $customer->id, 'items' => [
            ['product_id' => $headphones->id, 'quantity' => 3],
            ['product_id' => $shoes->id, 'product_variant_id' => $variant->id, 'quantity' => 2],
        ]])->assertCreated();

        $response = $this->getJson('/api/v1/inventory?sort=sku_asc')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3);

        $items = collect($response->json('data'))->keyBy('sku');
        $this->assertSame([
            'id' => 'product:'.$headphones->id, 'on_hand' => 25, 'reserved' => 3, 'available' => 22,
            'stock_status' => 'active', 'inventory_value' => '7500.00',
        ], collect($items['WH-001'])->only(['id', 'on_hand', 'reserved', 'available', 'stock_status', 'inventory_value'])->all());
        $this->assertSame(['id' => $category->id, 'name' => 'Electronics'], $items['WH-001']['category']);
        $this->assertSame('variant:'.$variant->id, $items['RS-002']['id']);
        $this->assertSame(['color' => 'Green', 'size' => '9'], $items['RS-002']['variant']);
        $this->assertSame([18, 2, 16], [$items['RS-002']['on_hand'], $items['RS-002']['reserved'], $items['RS-002']['available']]);
        $this->assertSame('out_of_stock', $items['RS-000']['stock_status']);

        $this->getJson('/api/v1/inventory/summary')->assertOk()->assertExactJson(['data' => [
            'total_items' => 3, 'total_skus' => 3, 'in_stock' => 2, 'low_stock' => 0, 'out_of_stock' => 1,
            'reserved_items' => 2, 'total_on_hand' => 43, 'total_reserved' => 5, 'total_available' => 38,
            'inventory_value' => '7500.00',
        ]]);
    }

    public function test_inventory_can_be_searched_filtered_and_sorted(): void
    {
        $merchant = Merchant::factory()->create();
        $bags = Category::factory()->create(['merchant_id' => $merchant->id]);
        $backpack = $this->activeProduct($merchant, ['name' => 'Backpack', 'sku' => 'BP-004', 'category_id' => $bags->id, 'stock_quantity' => 0]);
        $this->activeProduct($merchant, ['name' => 'Smart Watch', 'sku' => 'SW-003', 'stock_quantity' => 5, 'low_stock_threshold' => 10]);
        $this->activeProduct($merchant, ['name' => 'T-Shirt', 'sku' => 'TS-005', 'stock_quantity' => 40, 'low_stock_threshold' => 5]);
        $backpack->variants()->create(['sku' => 'BP-004-NAVY', 'color' => 'Navy', 'price' => 50, 'stock' => 7]);

        Sanctum::actingAs($merchant->user);

        $this->getJson('/api/v1/inventory?search=watch')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'SW-003');
        $this->getJson('/api/v1/inventory?search=navy')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'BP-004-NAVY');
        $this->getJson('/api/v1/inventory?stock_status=low_stock')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'SW-003');
        $this->getJson('/api/v1/inventory?stock_status=out_of_stock')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'BP-004');
        $this->getJson("/api/v1/inventory?category_id={$bags->id}&type=variant")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.sku', 'BP-004-NAVY');
        $this->getJson("/api/v1/inventory?product_id={$backpack->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(
            ['TS-005', 'BP-004-NAVY', 'SW-003', 'BP-004'],
            collect($this->getJson('/api/v1/inventory?sort=stock_desc')->assertOk()->json('data'))->pluck('sku')->all(),
        );
        $this->getJson('/api/v1/inventory?sort=bogus&stock_status=unknown')->assertUnprocessable()
            ->assertJsonValidationErrors(['sort', 'stock_status']);
    }

    public function test_product_inventory_details_include_each_stock_pool_and_recent_movements(): void
    {
        $merchant = Merchant::factory()->create();
        $product = $this->activeProduct($merchant, ['stock_quantity' => 4]);
        $variant = $product->variants()->create(['sku' => 'DETAIL-V1', 'price' => 10, 'stock' => 6]);

        Sanctum::actingAs($merchant->user);
        $this->postJson('/api/v1/inventory/adjust', [
            'product_id' => $product->id, 'product_variant_id' => $variant->id,
            'adjustment_type' => 'increase', 'quantity' => 4,
        ])->assertOk();

        $this->getJson("/api/v1/inventory/products/{$product->id}")->assertOk()
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.item_type', 'product')
            ->assertJsonPath('data.items.1.available', 10)
            ->assertJsonPath('data.recent_movements.0.type', 'stock_in')
            ->assertJsonPath('data.recent_movements.0.variant.sku', 'DETAIL-V1')
            ->assertJsonPath('data.recent_movements.0.user.id', $merchant->user->id);
    }

    public function test_update_stock_supports_increase_decrease_and_set_with_reference_details(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $product = $this->activeProduct($merchant, ['stock_quantity' => 25, 'low_stock_threshold' => 10]);

        Sanctum::actingAs($merchant->user);
        $this->postJson('/api/v1/orders', ['customer_id' => $customer->id, 'items' => [
            ['product_id' => $product->id, 'quantity' => 3],
        ]])->assertCreated();

        $this->postJson('/api/v1/inventory/adjust', [
            'product_id' => $product->id,
            'adjustment_type' => 'increase',
            'quantity' => 20,
            'reference_type' => 'purchase_order',
            'reference_number' => 'PO-2026-0156',
            'supplier' => 'ABC Electronics Inc.',
            'reference_date' => '2026-08-16',
            'notes' => 'Received new stock from supplier.',
        ])->assertOk()
            ->assertJsonPath('product.stock_quantity', 42)
            ->assertJsonPath('inventory_item.on_hand', 45)
            ->assertJsonPath('inventory_item.reserved', 3)
            ->assertJsonPath('inventory_item.available', 42)
            ->assertJsonPath('inventory_log.type', 'stock_in')
            ->assertJsonPath('inventory_log.reason', 'Stock added')
            ->assertJsonPath('inventory_log.previous_stock', 22)
            ->assertJsonPath('inventory_log.resulting_stock', 42)
            ->assertJsonPath('inventory_log.reference_number', 'PO-2026-0156')
            ->assertJsonPath('inventory_log.supplier', 'ABC Electronics Inc.')
            ->assertJsonPath('inventory_log.reference_date', '2026-08-16');

        $this->postJson('/api/v1/inventory/adjust', [
            'product_id' => $product->id, 'adjustment_type' => 'decrease', 'quantity' => 2, 'reason' => 'Damaged',
        ])->assertOk()
            ->assertJsonPath('inventory_item.available', 40)
            ->assertJsonPath('inventory_log.type', 'stock_out')
            ->assertJsonPath('inventory_log.quantity_change', -2);

        $this->postJson('/api/v1/inventory/adjust', [
            'product_id' => $product->id, 'adjustment_type' => 'set', 'quantity' => 30,
        ])->assertOk()
            ->assertJsonPath('inventory_item.on_hand', 30)
            ->assertJsonPath('inventory_item.available', 27)
            ->assertJsonPath('inventory_log.type', 'adjustment')
            ->assertJsonPath('inventory_log.quantity_change', -13);

        $this->getJson('/api/v1/inventory/logs?type=stock_in&search=PO-2026')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_type', 'purchase_order');
        $this->getJson('/api/v1/inventory/logs?type=sale')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference_type', 'order');
    }

    public function test_update_stock_rejects_negative_stock_and_invalid_payloads(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $product = $this->activeProduct($merchant, ['stock_quantity' => 5]);
        $otherVariant = $this->activeProduct($merchant)->variants()->create(['sku' => 'NOT-MINE', 'price' => 5, 'stock' => 5]);

        Sanctum::actingAs($merchant->user);
        $this->postJson('/api/v1/orders', ['customer_id' => $customer->id, 'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
        ]])->assertCreated();

        $adjust = fn (array $payload) => $this->postJson('/api/v1/inventory/adjust', ['product_id' => $product->id, ...$payload]);

        $adjust(['adjustment_type' => 'decrease', 'quantity' => 4])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $adjust(['adjustment_type' => 'set', 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $adjust(['adjustment_type' => 'set', 'quantity' => 5])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $adjust(['adjustment_type' => 'increase', 'quantity' => 0])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $adjust(['adjustment_type' => 'increase', 'quantity' => 1, 'quantity_change' => 1, 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['adjustment_type']);
        $adjust([])->assertUnprocessable()->assertJsonValidationErrors(['adjustment_type', 'quantity_change']);
        $adjust(['adjustment_type' => 'increase', 'quantity' => 1, 'product_variant_id' => $otherVariant->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['product_variant_id']);

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertSame(5, $otherVariant->fresh()->stock);
        $this->assertDatabaseCount('inventory_logs', 1);

        $adjust(['adjustment_type' => 'set', 'quantity' => 2])->assertOk()->assertJsonPath('inventory_item.available', 0);
    }

    public function test_merchants_cannot_view_or_manage_other_merchants_inventory(): void
    {
        $merchant = Merchant::factory()->create();
        $other = Merchant::factory()->create();
        $foreign = $this->activeProduct($other, ['stock_quantity' => 10]);
        $this->activeProduct($merchant);

        Sanctum::actingAs($merchant->user);
        $this->getJson('/api/v1/inventory')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/inventory?merchant_id={$other->id}")->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.merchant_id', $merchant->id);
        $this->getJson("/api/v1/inventory/products/{$foreign->id}")->assertNotFound();
        $this->postJson('/api/v1/inventory/adjust', [
            'product_id' => $foreign->id, 'adjustment_type' => 'increase', 'quantity' => 5,
        ])->assertNotFound();
        $this->assertSame(10, $foreign->fresh()->stock_quantity);

        $this->getJson('/api/v1/inventory/summary')->assertOk()->assertJsonPath('data.total_items', 1);
    }

    public function test_admin_inventory_access_requires_the_inventory_permission(): void
    {
        $this->seed(AdminAuthorizationSeeder::class);
        $merchant = Merchant::factory()->create();
        $product = $this->activeProduct($merchant, ['stock_quantity' => 10]);

        Sanctum::actingAs($this->adminWithRole(AdminRoleRegistry::CUSTOMER_SUPPORT), ['admin'], 'sanctum');
        $this->getJson('/api/admin/inventory')->assertForbidden();
        $this->getJson('/api/v1/inventory')->assertForbidden();
        $this->postJson('/api/v1/inventory/adjust', [
            'product_id' => $product->id, 'adjustment_type' => 'increase', 'quantity' => 1,
        ])->assertForbidden();

        $productManager = $this->adminWithRole(AdminRoleRegistry::PRODUCT_MANAGER);
        Sanctum::actingAs($productManager, ['storefront'], 'sanctum');
        $this->getJson('/api/v1/inventory')->assertForbidden();

        Sanctum::actingAs($productManager, ['admin'], 'sanctum');
        $this->getJson("/api/admin/inventory?merchant_id={$merchant->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/inventory/summary')->assertOk()->assertJsonPath('data.total_on_hand', 10);
        $this->getJson("/api/admin/inventory/products/{$product->id}")->assertOk();
        $this->postJson('/api/admin/inventory/adjust', [
            'product_id' => $product->id, 'adjustment_type' => 'increase', 'quantity' => 5, 'reason' => 'Admin correction',
        ])->assertOk()->assertJsonPath('inventory_log.merchant_id', $merchant->id);
        $this->assertSame(15, $product->fresh()->stock_quantity);
        $this->getJson('/api/admin/inventory/logs')->assertOk()->assertJsonPath('data.0.user.id', $productManager->id);
    }

    public function test_full_inventory_flow_from_adjustment_through_order_cancellation_and_return(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $product = $this->activeProduct($merchant, ['price' => 100, 'stock_quantity' => 0]);
        $variant = $product->variants()->create(['sku' => 'FLOW-V', 'price' => 150, 'stock' => 0]);
        Sanctum::actingAs($merchant->user);

        $this->postJson('/api/v1/inventory/adjust', ['product_id' => $product->id, 'adjustment_type' => 'increase', 'quantity' => 10])->assertOk();
        $this->postJson('/api/v1/inventory/adjust', [
            'product_id' => $product->id, 'product_variant_id' => $variant->id, 'adjustment_type' => 'set', 'quantity' => 5,
        ])->assertOk();

        $cancelledOrderId = $this->postJson('/api/v1/orders', ['customer_id' => $customer->id, 'items' => [
            ['product_id' => $product->id, 'quantity' => 4],
            ['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 2],
        ]])->assertCreated()->json('data.id');
        $this->assertStock($product, 10, 4, 6);
        $this->assertStock($product, 5, 2, 3, $variant);

        $this->patchJson("/api/v1/orders/{$cancelledOrderId}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertStock($product, 10, 0, 10);
        $this->assertStock($product, 5, 0, 5, $variant);
        $this->patchJson("/api/v1/orders/{$cancelledOrderId}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertStock($product, 10, 0, 10);

        $orderId = $this->postJson('/api/v1/orders', ['customer_id' => $customer->id, 'items' => [
            ['product_id' => $product->id, 'quantity' => 3],
        ]])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'processing'])->assertOk();
        $this->assertStock($product, 10, 3, 7);
        $this->postJson('/api/v1/payments', [
            'order_id' => $orderId, 'reference' => 'pay-inventory-flow', 'gateway' => 'manual', 'status' => 'completed', 'amount' => 300,
        ])->assertCreated();
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'out_for_delivery'])->assertOk();
        $this->assertStock($product, 10, 3, 7);
        $this->patchJson("/api/v1/orders/{$orderId}/status", ['status' => 'completed'])->assertOk();
        $this->assertStock($product, 7, 0, 7);

        $orderItemId = Order::findOrFail($orderId)->items()->value('id');
        $returnId = $this->postJson('/api/v1/return-requests', [
            'order_id' => $orderId, 'customer_id' => $customer->id, 'reason' => 'Damaged', 'notes' => 'Broken on arrival',
            'items' => [['order_item_id' => $orderItemId, 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/return-requests/{$returnId}", ['status' => 'approved'])->assertOk();
        $refundId = $this->postJson('/api/v1/refunds', [
            'order_id' => $orderId, 'payment_id' => Payment::where('order_id', $orderId)->value('id'),
            'reference' => 'refund-inventory-flow', 'amount' => 100, 'status' => 'processed',
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/return-requests/{$returnId}", ['status' => 'processed', 'refund_id' => $refundId])->assertOk();
        $this->assertStock($product, 8, 0, 8);

        $this->assertSame(
            ['stock_in', 'adjustment', 'sale', 'sale', 'cancellation', 'cancellation', 'sale', 'return'],
            collect($this->getJson('/api/v1/inventory/logs?sort=oldest')->assertOk()->json('data'))->pluck('type')->all(),
        );
        $this->getJson("/api/v1/inventory/logs?product_variant_id={$variant->id}")->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/inventory/logs?type=return')->assertOk()
            ->assertJsonPath('data.0.reference_type', 'return_request')
            ->assertJsonPath('data.0.reference_number', (string) $returnId);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function activeProduct(Merchant $merchant, array $attributes = []): Product
    {
        return Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => ProductStatus::Active,
            'track_inventory' => true,
            'low_stock_threshold' => 5,
            'cost_price' => null,
            ...$attributes,
        ]);
    }

    protected function assertStock(Product $product, int $onHand, int $reserved, int $available, ?ProductVariant $variant = null): void
    {
        $item = collect($this->getJson("/api/v1/inventory/products/{$product->id}")->assertOk()->json('data.items'))
            ->firstWhere('product_variant_id', $variant?->id);

        $this->assertSame(
            ['on_hand' => $onHand, 'reserved' => $reserved, 'available' => $available],
            collect($item)->only(['on_hand', 'reserved', 'available'])->all(),
        );
    }

    protected function adminWithRole(string $roleSlug): User
    {
        $admin = User::factory()->admin()->create();
        $admin->adminRoles()->sync([AdminRole::where('slug', $roleSlug)->firstOrFail()->id]);

        return $admin->fresh(['adminRoles.permissions', 'adminPermissions']);
    }
}
