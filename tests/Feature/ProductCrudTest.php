<?php

namespace Tests\Feature;

use App\Enums\MerchantStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_can_crud_products(): void
    {
        $merchant = Merchant::factory()->create(['status' => MerchantStatus::Verified]);
        $otherMerchant = Merchant::factory()->create();
        $user = $merchant->user;
        $category = Category::factory()->create(['merchant_id' => $merchant->id]);

        Sanctum::actingAs($user);

        $createResponse = $this->postJson('/api/v1/products', [
            'merchant_id' => $otherMerchant->id,
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

        $this->getJson("/api/v1/products/{$productId}")
            ->assertOk()
            ->assertJsonPath('data.id', $productId);

        $this->patchJson("/api/v1/products/{$productId}", [
            'name' => 'Fresh Green Apples',
            'price' => 109.25,
            'stock_quantity' => 30,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Fresh Green Apples')
            ->assertJsonPath('data.stock_quantity', 30);

        $this->patchJson("/api/v1/products/{$productId}", [
            'status' => ProductStatus::Archived->value,
        ])->assertOk()
            ->assertJsonPath('data.status', ProductStatus::Archived->value);

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

        $this->patchJson("/api/v1/products/{$product->id}", [
            'name' => 'Unauthorized update',
        ])->assertNotFound();

        $this->deleteJson("/api/v1/products/{$product->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_product_endpoints_require_authentication_and_list_only_the_merchants_products(): void
    {
        $merchant = Merchant::factory()->create();
        $otherMerchant = Merchant::factory()->create();
        $ownProduct = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'category_id' => null,
        ]);
        Product::factory()->create([
            'merchant_id' => $otherMerchant->id,
            'category_id' => null,
        ]);

        $this->getJson('/api/v1/products')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/products', [
            'name' => 'Unlinked Merchant Product',
            'slug' => 'unlinked-merchant-product',
            'sku' => 'UNLINKED-001',
            'status' => ProductStatus::Draft->value,
            'price' => 10,
            'stock_quantity' => 1,
        ])->assertUnprocessable()
            ->assertInvalid(['merchant']);

        Sanctum::actingAs($merchant->user);

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownProduct->id);
    }

    public function test_sku_must_be_unique_but_an_existing_product_can_keep_its_sku(): void
    {
        $merchant = Merchant::factory()->create();
        $otherMerchant = Merchant::factory()->create();
        Product::factory()->create([
            'merchant_id' => $otherMerchant->id,
            'category_id' => null,
            'sku' => 'SHARED-SKU',
        ]);
        $product = Product::factory()->create([
            'merchant_id' => $merchant->id,
            'category_id' => null,
            'sku' => 'OWN-SKU',
        ]);

        Sanctum::actingAs($merchant->user);

        $this->postJson('/api/v1/products', [
            'name' => 'Duplicate SKU',
            'slug' => 'duplicate-sku',
            'sku' => 'SHARED-SKU',
            'status' => ProductStatus::Draft->value,
            'price' => 12.50,
            'stock_quantity' => 2,
        ])->assertUnprocessable()
            ->assertInvalid(['sku']);

        $this->patchJson("/api/v1/products/{$product->id}", [
            'sku' => 'OWN-SKU',
        ])->assertOk()
            ->assertJsonPath('data.sku', 'OWN-SKU');
    }

    public function test_product_images_upload_and_replace_through_multipart_requests(): void
    {
        Storage::fake('public');
        $merchant = Merchant::factory()->create();
        $category = Category::factory()->create(['merchant_id' => $merchant->id]);

        Sanctum::actingAs($merchant->user);

        $createResponse = $this->withHeader('Accept', 'application/json')->post('/api/v1/products', [
            'category_id' => $category->id,
            'name' => 'Photo Product',
            'slug' => 'photo-product',
            'sku' => 'PHOTO-001',
            'status' => ProductStatus::Draft->value,
            'price' => '19.99',
            'stock_quantity' => '4',
            'images' => [UploadedFile::fake()->image('first.png')],
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.merchant_id', $merchant->id);
        $originalPath = $createResponse->json('data.images.0');
        $this->assertIsString($originalPath);
        Storage::disk('public')->assertExists($originalPath);

        $updateResponse = $this->withHeader('Accept', 'application/json')->post("/api/v1/products/{$createResponse->json('data.id')}", [
            '_method' => 'PATCH',
            'images' => [UploadedFile::fake()->image('replacement.png')],
        ]);

        $updateResponse->assertOk()
            ->assertJsonCount(1, 'data.images');

        $this->assertNotSame($originalPath, $updateResponse->json('data.images.0'));
        Storage::disk('public')->assertExists($updateResponse->json('data.images.0'));
    }

    public function test_product_image_validation_rejects_non_image_uploads(): void
    {
        $merchant = Merchant::factory()->create();
        Sanctum::actingAs($merchant->user);

        $this->withHeader('Accept', 'application/json')->post('/api/v1/products', [
            'name' => 'Invalid Image Product',
            'slug' => 'invalid-image-product',
            'sku' => 'INVALID-IMAGE-001',
            'status' => ProductStatus::Draft->value,
            'price' => '10.00',
            'stock_quantity' => '1',
            'images' => [UploadedFile::fake()->create('document.pdf', 20, 'application/pdf')],
        ])->assertUnprocessable()
            ->assertInvalid(['images.0']);
    }
}
