<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->after('merchant_id')->constrained('categories')->nullOnDelete();
            $table->string('image_path')->nullable()->after('description');
            $table->unsignedInteger('sort_order')->default(0)->after('image_path');
            $table->boolean('is_active')->default(true)->after('sort_order');
            $table->boolean('show_in_nav')->default(true)->after('is_active');
            $table->string('meta_title')->nullable()->after('show_in_nav');
            $table->string('meta_description', 500)->nullable()->after('meta_title');
        });

        $hasGlobalSlugIndex = Schema::hasIndex('categories', ['slug'], 'unique');

        Schema::table('categories', function (Blueprint $table) use ($hasGlobalSlugIndex): void {
            if ($hasGlobalSlugIndex) {
                $table->dropUnique(['slug']);
            }

            $table->unique(['merchant_id', 'slug']);
            $table->index(['merchant_id', 'is_active']);
            $table->index(['merchant_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        $hasDuplicateSlugs = DB::table('categories')
            ->select('slug')
            ->groupBy('slug')
            ->havingRaw('count(*) > 1')
            ->exists();

        Schema::table('categories', function (Blueprint $table) use ($hasDuplicateSlugs): void {
            $table->dropIndex(['merchant_id', 'sort_order']);
            $table->dropIndex(['merchant_id', 'is_active']);
            $table->dropUnique(['merchant_id', 'slug']);

            if (! $hasDuplicateSlugs) {
                $table->unique('slug');
            }
        });

        Schema::table('categories', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn([
                'image_path', 'sort_order', 'is_active', 'show_in_nav', 'meta_title', 'meta_description',
            ]);
        });
    }
};
