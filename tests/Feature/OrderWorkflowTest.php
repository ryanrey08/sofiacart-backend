<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_can_create_order_with_valid_price_and_stock_is_deducted_and_logged(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => ProductStatus::Active,
            'price' => 150.00,
            'stock_quantity' => 10,
        ]);

        Sanctum::actingAs($merchant->user);

        $response = $this->postJson('/api/v1/orders', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'unit_price' => 150.00,
                ],
            ],
            'notes' => 'Customer requested rush delivery',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.merchant_id', $merchant->id)
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.total_amount', '450.00')
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.items.0.unit_price', '150.00')
            ->assertJsonPath('data.items.0.total_price', '450.00');

        $orderId = $response->json('data.id');

        // Verify product stock deducted
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 7,
        ]);

        // Verify inventory log recorded
        $this->assertDatabaseHas('inventory_logs', [
            'merchant_id' => $merchant->id,
            'product_id' => $product->id,
            'quantity_change' => -3,
            'resulting_stock' => 7,
        ]);

        // Verify order items saved
        $this->assertDatabaseHas('order_items', [
            'order_id' => $orderId,
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_price' => 150.00,
            'total_price' => 450.00,
        ]);
    }

    public function test_order_creation_rejects_price_tampering(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => ProductStatus::Active,
            'price' => 200.00,
            'stock_quantity' => 10,
        ]);

        Sanctum::actingAs($merchant->user);

        // Attempt to pass lower unit price 50.00 instead of real 200.00
        $response = $this->postJson('/api/v1/orders', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 50.00,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.unit_price']);

        // Stock must not change
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 10,
        ]);
    }

    public function test_order_creation_prevents_overselling(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => ProductStatus::Active,
            'price' => 50.00,
            'stock_quantity' => 2,
        ]);

        Sanctum::actingAs($merchant->user);

        // Try ordering quantity 5 when only 2 are available
        $response = $this->postJson('/api/v1/orders', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'unit_price' => 50.00,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);

        // Stock remains untouched
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 2,
        ]);
    }

    public function test_order_creation_rejects_inactive_or_draft_products(): void
    {
        $merchant = Merchant::factory()->create();
        $customer = Customer::factory()->create(['merchant_id' => $merchant->id]);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'status' => ProductStatus::Draft,
            'price' => 50.00,
            'stock_quantity' => 10,
        ]);

        Sanctum::actingAs($merchant->user);

        $response = $this->postJson('/api/v1/orders', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 50.00,
                ],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_order_cancellation_restores_inventory_idempotently(): void
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

        $createResponse = $this->postJson('/api/v1/orders', [
            'customer_id' => $customer->id,
            'items' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 4,
                    'unit_price' => 100.00,
                ],
            ],
        ]);

        $createResponse->assertCreated();
        $orderId = $createResponse->json('data.id');

        // Stock deducted to 6
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 6,
        ]);

        // Cancel the order
        $cancelResponse = $this->patchJson("/api/v1/orders/{$orderId}", [
            'status' => OrderStatus::Cancelled->value,
        ]);

        $cancelResponse->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value)
            ->assertJsonPath('data.inventory_restored', true);

        // Stock restored to 10
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 10,
        ]);

        // Inventory log created for restoration
        $this->assertDatabaseHas('inventory_logs', [
            'merchant_id' => $merchant->id,
            'product_id' => $product->id,
            'quantity_change' => 4,
            'resulting_stock' => 10,
        ]);

        // Updating order notes on already cancelled order must NOT restore stock again
        $this->patchJson("/api/v1/orders/{$orderId}", [
            'notes' => 'Updated cancellation notes',
        ])->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock_quantity' => 10,
        ]);
    }

    public function test_merchant_cannot_order_another_merchants_product_or_customer(): void
    {
        $merchant = Merchant::factory()->create();
        $otherMerchant = Merchant::factory()->create();
        $otherCustomer = Customer::factory()->create(['merchant_id' => $otherMerchant->id]);
        $otherProduct = Product::factory()->create([
            'merchant_id' => $otherMerchant->id,
            'status' => ProductStatus::Active,
            'price' => 80.00,
            'stock_quantity' => 10,
        ]);

        Sanctum::actingAs($merchant->user);

        // Attempt ordering with other merchant's customer
        $this->postJson('/api/v1/orders', [
            'customer_id' => $otherCustomer->id,
            'items' => [
                [
                    'product_id' => $otherProduct->id,
                    'quantity' => 1,
                    'unit_price' => 80.00,
                ],
            ],
        ])->assertNotFound();

        // With own customer but other merchant's product
        $ownCustomer = Customer::factory()->create(['merchant_id' => $merchant->id]);

        $this->postJson('/api/v1/orders', [
            'customer_id' => $ownCustomer->id,
            'items' => [
                [
                    'product_id' => $otherProduct->id,
                    'quantity' => 1,
                    'unit_price' => 80.00,
                ],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.product_id']);
    }
}
