<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsMerchant(): Merchant
    {
        $merchant = Merchant::factory()->create();
        Sanctum::actingAs($merchant->user);

        return $merchant;
    }

    public function test_category_is_created_with_management_fields_and_auto_generated_slug(): void
    {
        Storage::fake('public');
        $merchant = $this->actingAsMerchant();
        $parent = Category::factory()->create(['merchant_id' => $merchant->id]);

        $response = $this->post('/api/v1/categories', [
            'name' => 'Home & Living',
            'parent_id' => $parent->id,
            'description' => '<p>Cozy <strong>home</strong> goods<script>alert(1)</script></p><a href="javascript:alert(1)" onclick="x()">link</a>',
            'image' => UploadedFile::fake()->image('category.png', 600, 400),
            'sort_order' => 3,
            'is_active' => 'false',
            'show_in_nav' => '1',
            'meta_title' => 'Home & Living | SofiaCart',
            'meta_description' => 'Furniture and decor.',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'home-living')
            ->assertJsonPath('data.merchant_id', $merchant->id)
            ->assertJsonPath('data.parent.id', $parent->id)
            ->assertJsonPath('data.description', '<p>Cozy <strong>home</strong> goods</p><a>link</a>')
            ->assertJsonPath('data.sort_order', 3)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.show_in_nav', true)
            ->assertJsonPath('data.products_count', 0);

        $imagePath = $response->json('data.image_path');
        Storage::disk('public')->assertExists($imagePath);
        $this->assertStringContainsString($imagePath, $response->json('data.image_url'));

        $this->postJson('/api/v1/categories', ['name' => 'Home & Living'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'home-living-2');
    }

    public function test_category_validation_rules(): void
    {
        $merchant = $this->actingAsMerchant();
        Category::factory()->create(['merchant_id' => $merchant->id, 'slug' => 'electronics']);
        Category::factory()->create(['slug' => 'apparel']);

        $this->postJson('/api/v1/categories', [
            'slug' => 'Not Valid!',
            'description' => str_repeat('a', 501),
            'is_active' => 'maybe',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'slug', 'description', 'is_active']);

        $this->postJson('/api/v1/categories', [
            'name' => 'Electronics',
            'slug' => 'electronics',
            'image' => UploadedFile::fake()->create('category.gif', 10, 'image/gif'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['slug', 'image']);

        $this->postJson('/api/v1/categories', [
            'name' => 'Large image',
            'image' => UploadedFile::fake()->image('category.jpg')->size(2049),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);

        $this->postJson('/api/v1/categories', [
            'name' => 'Rich text within limit',
            'description' => '<p>'.str_repeat('a', 500).'</p>',
        ])->assertCreated();

        $this->postJson('/api/v1/categories', ['name' => 'Apparel', 'slug' => 'apparel'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'apparel');
    }

    public function test_parent_must_belong_to_the_same_store_and_cannot_create_cycles(): void
    {
        $merchant = $this->actingAsMerchant();
        $foreignCategory = Category::factory()->create();
        $parent = Category::factory()->create(['merchant_id' => $merchant->id]);
        $child = Category::factory()->childOf($parent)->create();

        $this->postJson('/api/v1/categories', ['name' => 'Foreign child', 'parent_id' => $foreignCategory->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);

        $this->patchJson("/api/v1/categories/{$parent->id}", ['parent_id' => $child->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);

        $this->patchJson("/api/v1/categories/{$parent->id}", ['parent_id' => $parent->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['parent_id']);
    }

    public function test_index_supports_search_filters_sorting_and_products_count(): void
    {
        $merchant = $this->actingAsMerchant();
        $electronics = Category::factory()->create(['merchant_id' => $merchant->id, 'name' => 'Electronics', 'description' => 'Gadgets']);
        $apparel = Category::factory()->create(['merchant_id' => $merchant->id, 'name' => 'Apparel', 'description' => 'Clothing']);
        $toys = Category::factory()->inactive()->create(['merchant_id' => $merchant->id, 'name' => 'Toys', 'description' => 'Fun gadgets']);
        $phones = Category::factory()->childOf($electronics)->create(['name' => 'Phones']);
        Category::factory()->create(['name' => 'Foreign Electronics']);
        Product::factory()->count(3)->create(['merchant_id' => $merchant->id, 'category_id' => $apparel->id]);
        Product::factory()->create(['merchant_id' => $merchant->id, 'category_id' => $electronics->id]);

        $this->getJson('/api/v1/categories?per_page=10')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 4);

        $this->getJson('/api/v1/categories?search=gadgets')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/categories?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $toys->id);

        $this->getJson('/api/v1/categories?status=active&parent_id=none&sort=name_asc')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$apparel->id, $electronics->id]);

        $this->getJson("/api/v1/categories?parent_id={$electronics->id}")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$phones->id])
            ->assertJsonPath('data.0.parent.name', 'Electronics');

        $this->getJson('/api/v1/categories?sort=name_desc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $toys->id);

        $this->getJson('/api/v1/categories?sort=products_desc')
            ->assertOk()
            ->assertJsonPath('data.0.id', $apparel->id)
            ->assertJsonPath('data.0.products_count', 3)
            ->assertJsonPath('data.1.id', $electronics->id)
            ->assertJsonPath('data.1.products_count', 1)
            ->assertJsonPath('data.1.children_count', 1);
    }

    public function test_stats_are_scoped_to_the_merchant_store(): void
    {
        $merchant = $this->actingAsMerchant();
        $active = Category::factory()->count(2)->create(['merchant_id' => $merchant->id]);
        Category::factory()->inactive()->create(['merchant_id' => $merchant->id]);
        Product::factory()->count(2)->create(['merchant_id' => $merchant->id, 'category_id' => $active->first()->id]);
        Product::factory()->create(['merchant_id' => $merchant->id, 'category_id' => null]);
        $foreign = Category::factory()->create();
        Product::factory()->create(['merchant_id' => $foreign->merchant_id, 'category_id' => $foreign->id]);

        $this->getJson('/api/v1/categories/stats')
            ->assertOk()
            ->assertExactJson(['data' => [
                'total' => 3,
                'active' => 2,
                'inactive' => 1,
                'total_products' => 2,
            ]]);
    }

    public function test_show_update_image_upload_and_status_toggle(): void
    {
        Storage::fake('public');
        $merchant = $this->actingAsMerchant();
        $category = Category::factory()->create(['merchant_id' => $merchant->id, 'slug' => 'electronics']);
        Category::factory()->create(['merchant_id' => $merchant->id, 'slug' => 'apparel']);

        $this->getJson("/api/v1/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.products_count', 0)
            ->assertJsonPath('data.parent', null)
            ->assertJsonStructure(['data' => ['image_url', 'created_at', 'updated_at', 'meta_title', 'meta_description']]);

        $this->putJson("/api/v1/categories/{$category->id}", ['slug' => 'apparel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->putJson("/api/v1/categories/{$category->id}", ['name' => 'Gadgets', 'slug' => 'electronics', 'show_in_nav' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Gadgets')
            ->assertJsonPath('data.show_in_nav', false);

        $first = $this->post("/api/v1/categories/{$category->id}/image", [
            'image' => UploadedFile::fake()->image('first.webp', 600, 400),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.image_path');

        $second = $this->post("/api/v1/categories/{$category->id}/image", [
            'image' => UploadedFile::fake()->image('second.jpg', 600, 400),
        ], ['Accept' => 'application/json'])->assertOk()->json('data.image_path');

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->patchJson("/api/v1/categories/{$category->id}", ['remove_image' => true])
            ->assertOk()
            ->assertJsonPath('data.image_url', null);
        Storage::disk('public')->assertMissing($second);

        $this->patchJson("/api/v1/categories/{$category->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/v1/categories/{$category->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->patchJson("/api/v1/categories/{$category->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_bulk_actions_are_scoped_to_the_merchant_store(): void
    {
        $merchant = $this->actingAsMerchant();
        $categories = Category::factory()->count(3)->create(['merchant_id' => $merchant->id]);
        $foreign = Category::factory()->create();
        $product = Product::factory()->create(['merchant_id' => $merchant->id, 'category_id' => $categories[0]->id]);
        $ids = $categories->take(2)->pluck('id')->all();

        $this->postJson('/api/v1/categories/bulk', ['action' => 'deactivate', 'ids' => $ids])
            ->assertOk()
            ->assertJsonPath('data.affected', 2);
        $this->assertSame(2, Category::whereKey($ids)->where('is_active', false)->count());

        $this->postJson('/api/v1/categories/bulk', ['action' => 'activate', 'ids' => $ids])
            ->assertOk();
        $this->assertSame(2, Category::whereKey($ids)->where('is_active', true)->count());

        $this->postJson('/api/v1/categories/bulk', ['action' => 'delete', 'ids' => [...$ids, $foreign->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['ids']);
        $this->assertSame(2, Category::whereKey($ids)->count());

        $this->postJson('/api/v1/categories/bulk', ['action' => 'archive', 'ids' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['action', 'ids']);

        $this->postJson('/api/v1/categories/bulk', ['action' => 'delete', 'ids' => $ids])
            ->assertOk()
            ->assertJsonPath('data.affected', 2);

        $this->assertDatabaseMissing('categories', ['id' => $ids[0]]);
        $this->assertDatabaseHas('categories', ['id' => $foreign->id]);
        $this->assertNull($product->fresh()->category_id);
    }

    public function test_merchant_cannot_manage_another_stores_category(): void
    {
        $this->actingAsMerchant();
        $foreign = Category::factory()->create();

        $this->patchJson("/api/v1/categories/{$foreign->id}/status")->assertNotFound();
        $this->post("/api/v1/categories/{$foreign->id}/image", [
            'image' => UploadedFile::fake()->image('category.png'),
        ], ['Accept' => 'application/json'])->assertNotFound();

        $this->assertTrue($foreign->fresh()->is_active);
    }

    public function test_admin_slug_uniqueness_and_stats_are_scoped_to_the_selected_store(): void
    {
        $merchant = Merchant::factory()->create();
        $otherMerchant = Merchant::factory()->create();
        $category = Category::factory()->create(['merchant_id' => $merchant->id, 'slug' => 'electronics']);
        Category::factory()->create(['merchant_id' => $merchant->id, 'slug' => 'apparel']);
        Category::factory()->inactive()->create(['merchant_id' => $otherMerchant->id]);
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/v1/categories', ['merchant_id' => $merchant->id, 'name' => 'Electronics', 'slug' => 'electronics'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->postJson('/api/v1/categories', ['merchant_id' => $otherMerchant->id, 'name' => 'Electronics', 'slug' => 'electronics'])
            ->assertCreated()
            ->assertJsonPath('data.merchant_id', $otherMerchant->id);

        $this->patchJson("/api/v1/categories/{$category->id}", ['slug' => 'apparel'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->getJson("/api/v1/categories/stats?merchant_id={$otherMerchant->id}")
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.inactive', 1);
    }

    public function test_category_seeder_creates_mockup_categories(): void
    {
        $merchant = Merchant::factory()->create();

        $this->seed(CategorySeeder::class);

        $this->assertDatabaseHas('categories', ['merchant_id' => $merchant->id, 'name' => 'Electronics', 'slug' => 'electronics']);
        $this->assertDatabaseHas('categories', ['merchant_id' => $merchant->id, 'name' => 'Apparel']);
        $this->assertDatabaseHas('categories', ['merchant_id' => $merchant->id, 'name' => 'Home & Living', 'slug' => 'home-living']);
        $this->assertSame(
            Category::where('slug', 'electronics')->value('id'),
            Category::where('slug', 'smartphones')->value('parent_id'),
        );
    }
}
