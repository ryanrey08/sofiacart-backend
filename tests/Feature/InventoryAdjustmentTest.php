<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_adjustment_updates_stock_and_creates_log(): void
    {
        $merchant = Merchant::factory()->create();
        $category = Category::factory()->create(['merchant_id' => $merchant->id]);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'category_id' => $category->id,
            'status' => ProductStatus::Active,
            'stock_quantity' => 10,
        ]);

        Sanctum::actingAs($merchant->user);

        $response = $this->postJson('/api/v1/inventory/adjust', [
            'product_id' => $product->id,
            'reason' => 'restock',
            'quantity_change' => 5,
            'notes' => 'New stock received',
        ]);

        $response->assertOk()
            ->assertJsonPath('product.stock_quantity', 15)
            ->assertJsonPath('inventory_log.resulting_stock', 15);

        $this->assertDatabaseHas('inventory_logs', [
            'product_id' => $product->id,
            'reason' => 'restock',
            'quantity_change' => 5,
            'resulting_stock' => 15,
        ]);
    }

    public function test_inventory_adjustment_cannot_result_in_negative_stock(): void
    {
        $merchant = Merchant::factory()->create();
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'category_id' => null,
            'stock_quantity' => 2,
        ]);

        Sanctum::actingAs($merchant->user);

        $this->postJson('/api/v1/inventory/adjust', [
            'product_id' => $product->id,
            'reason' => 'damage',
            'quantity_change' => -3,
        ])->assertStatus(422)
            ->assertInvalid(['quantity_change']);
    }
}
