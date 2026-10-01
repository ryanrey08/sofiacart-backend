<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('short_description')->nullable()->after('description');
            $table->text('full_description')->nullable()->after('short_description');
            $table->decimal('regular_price', 12, 2)->nullable()->after('price');
            $table->decimal('sale_price', 12, 2)->nullable()->after('regular_price');
            $table->decimal('cost_price', 12, 2)->nullable()->after('sale_price');
            $table->string('brand')->nullable()->after('cost_price');
            $table->string('condition')->nullable()->after('brand');
            $table->decimal('weight', 10, 3)->nullable()->after('condition');
            $table->json('tags')->nullable()->after('weight');
            $table->boolean('track_inventory')->default(true)->after('tags');
            $table->unsignedInteger('low_stock_threshold')->default(0)->after('track_inventory');
            $table->decimal('length', 10, 2)->nullable()->after('low_stock_threshold');
            $table->decimal('width', 10, 2)->nullable()->after('length');
            $table->decimal('height', 10, 2)->nullable()->after('width');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn([
                'short_description', 'full_description', 'regular_price', 'sale_price',
                'cost_price', 'brand', 'condition', 'weight', 'tags', 'track_inventory',
                'low_stock_threshold', 'length', 'width', 'height',
            ]);
        });
    }
};
