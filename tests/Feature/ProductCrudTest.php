<?php

namespace Tests\Feature;

use App\Enums\MerchantStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_can_crud_products(): void
    {
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Verified]);
        $user = $merchant->user;
        $category = Category::factory()->create(['merchant_id' => $merchant->id]);

        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/products', [
            'category_id' => $category->id,
            'name' => 'Fresh Apples',
            'slug' => 'fresh-apples',
            'sku' => 'SKU-APPLE-001',
            'description' => 'Crisp and sweet apples',
            'status' => ProductStatus::Active->value,
            'price' => 99.50,
            'stock_quantity' => 25,
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.name', 'Fresh Apples')
            ->assertJsonPath('data.merchant_id', $merchant->id);

        $productId = $createResponse->json('data.id');

        $this->getJson('/api/v1/products?search=Apples')
            ->assertOk()
            ->assertJsonPath('data.0.id', $productId);

        $this->patchJson("/api/v1/products/{$productId}", [
            'name' => 'Fresh Green Apples',
            'price' => 109.25,
            'stock_quantity' => 30,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Fresh Green Apples')
            ->assertJsonPath('data.stock_quantity', 30);

        $this->deleteJson("/api/v1/products/{$productId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('products', ['id' => $productId]);
    }

    public function test_merchant_cannot_access_another_merchants_product(): void
    {
        $merchant = Merchant::factory()->create();
        $otherMerchant = Merchant::factory()->create();
        $product = Product::factory()->create([
            'merchant_id' => $otherMerchant->id,
            'category_id' => null,
        ]);

        Sanctum::actingAs($merchant->user);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertNotFound();
    }
}
