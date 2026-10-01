<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Merchant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    /**
     * Sample categories matching the Category Management mockup.
     *
     * @var list<array{name: string, description: string, is_active: bool, children?: list<string>}>
     */
    public const CATEGORIES = [
        ['name' => 'Electronics', 'description' => 'Phones, laptops, audio, and smart devices.', 'is_active' => true, 'children' => ['Smartphones', 'Laptops', 'Audio']],
        ['name' => 'Apparel', 'description' => 'Clothing, shoes, and accessories for everyone.', 'is_active' => true, 'children' => ["Men's Clothing", "Women's Clothing"]],
        ['name' => 'Home & Living', 'description' => 'Furniture, decor, and kitchen essentials.', 'is_active' => true, 'children' => ['Kitchen & Dining', 'Home Decor']],
        ['name' => 'Beauty & Health', 'description' => 'Skincare, makeup, and personal care products.', 'is_active' => true],
        ['name' => 'Sports & Outdoors', 'description' => 'Fitness gear, outdoor equipment, and activewear.', 'is_active' => true],
        ['name' => 'Toys & Games', 'description' => 'Toys, board games, and hobby kits for all ages.', 'is_active' => true],
        ['name' => 'Books & Stationery', 'description' => 'Books, notebooks, and office supplies.', 'is_active' => false],
        ['name' => 'Groceries', 'description' => 'Pantry staples, snacks, and beverages.', 'is_active' => false],
    ];

    /**
     * Seed the sample categories for every merchant.
     */
    public function run(): void
    {
        Merchant::query()->each(fn (Merchant $merchant) => self::seedForMerchant($merchant));
    }

    /**
     * Seed the sample categories for a merchant and return the top-level categories.
     *
     * @return Collection<int, Category>
     */
    public static function seedForMerchant(Merchant $merchant): Collection
    {
        return collect(self::CATEGORIES)->map(function (array $definition, int $index) use ($merchant): Category {
            $parent = self::upsertCategory($merchant, $definition['name'], [
                'description' => "<p>{$definition['description']}</p>",
                'sort_order' => $index + 1,
                'is_active' => $definition['is_active'],
                'show_in_nav' => $definition['is_active'],
                'meta_title' => "{$definition['name']} | Shop Online",
                'meta_description' => "Browse our {$definition['name']} collection. ".$definition['description'],
            ]);

            foreach ($definition['children'] ?? [] as $childIndex => $childName) {
                self::upsertCategory($merchant, $childName, [
                    'parent_id' => $parent->id,
                    'description' => "<p>{$childName} in {$definition['name']}.</p>",
                    'sort_order' => $childIndex + 1,
                    'is_active' => $definition['is_active'],
                    'show_in_nav' => false,
                ]);
            }

            return $parent;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected static function upsertCategory(Merchant $merchant, string $name, array $attributes): Category
    {
        return Category::updateOrCreate(
            ['merchant_id' => $merchant->id, 'slug' => Str::slug($name)],
            ['name' => $name, ...$attributes],
        );
    }
}
