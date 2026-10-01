<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_merchant_can_crud_categories(): void
    {
        $merchant = Merchant::factory()->create();
        $otherMerchant = Merchant::factory()->create();
        Sanctum::actingAs($merchant->user);

        // Create
        $response = $this->postJson('/api/v1/categories', [
            'merchant_id' => $otherMerchant->id, // Should be overridden to authenticated merchant
            'name' => 'Bakery & Pastry',
            'slug' => 'bakery-and-pastry',
            'description' => 'Freshly baked goods',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Bakery & Pastry')
            ->assertJsonPath('data.merchant_id', $merchant->id);

        $categoryId = $response->json('data.id');

        // List
        $this->getJson('/api/v1/categories?search=Bakery')
            ->assertOk()
            ->assertJsonPath('data.0.id', $categoryId);

        // Show
        $this->getJson("/api/v1/categories/{$categoryId}")
            ->assertOk()
            ->assertJsonPath('data.id', $categoryId);

        // Update
        $this->patchJson("/api/v1/categories/{$categoryId}", [
            'name' => 'Artisan Bakery',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Artisan Bakery');

        // Delete
        $this->deleteJson("/api/v1/categories/{$categoryId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('categories', ['id' => $categoryId]);
    }

    public function test_merchant_cannot_access_or_modify_another_merchants_category(): void
    {
        $merchant = Merchant::factory()->create();
        $otherMerchant = Merchant::factory()->create();
        $category = Category::factory()->create(['merchant_id' => $otherMerchant->id]);

        Sanctum::actingAs($merchant->user);

        $this->getJson("/api/v1/categories/{$category->id}")
            ->assertNotFound();

        $this->patchJson("/api/v1/categories/{$category->id}", [
            'name' => 'Unauthorized Category Change',
        ])->assertNotFound();

        $this->deleteJson("/api/v1/categories/{$category->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }
}
