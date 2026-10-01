<?php

use App\Enums\CustomerType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('first_name', 100)->nullable()->after('name');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('customer_type', 20)->default(CustomerType::Regular->value)->after('phone');
            $table->boolean('is_active')->default(true)->after('customer_type');
            $table->date('birthday')->nullable()->after('is_active');
            $table->string('gender', 20)->nullable()->after('birthday');
            $table->string('tin', 32)->nullable()->after('gender');
            $table->string('address_line1')->nullable()->after('address');
            $table->string('address_line2')->nullable()->after('address_line1');
            $table->string('city', 100)->nullable()->after('address_line2');
            $table->string('province', 100)->nullable()->after('city');
            $table->string('postal_code', 20)->nullable()->after('province');
            $table->string('country', 100)->nullable()->after('postal_code');
            $table->text('notes')->nullable()->after('country');
            $table->json('tags')->nullable()->after('notes');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->index(['merchant_id', 'customer_type']);
            $table->index(['merchant_id', 'is_active']);
            $table->index(['merchant_id', 'created_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index(['customer_id', 'ordered_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['customer_id', 'ordered_at']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['merchant_id', 'created_at']);
            $table->dropIndex(['merchant_id', 'is_active']);
            $table->dropIndex(['merchant_id', 'customer_type']);
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropColumn([
                'first_name', 'last_name', 'customer_type', 'is_active', 'birthday', 'gender', 'tin',
                'address_line1', 'address_line2', 'city', 'province', 'postal_code', 'country', 'notes', 'tags',
            ]);
        });
    }
};
