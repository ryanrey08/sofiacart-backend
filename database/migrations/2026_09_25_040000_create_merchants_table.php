<?php

use App\Enums\MerchantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('business_type');
            $table->string('business_permit_number');
            $table->string('tin')->unique();
            $table->string('business_category');
            $table->text('business_address');
            $table->string('city');
            $table->string('province');
            $table->string('zip_code', 10);
            $table->string('business_permit_path');
            $table->string('store_name');
            $table->string('store_slug')->unique();
            $table->string('store_category');
            $table->text('store_description')->nullable();
            $table->text('store_address');
            $table->string('contact_phone', 20);
            $table->string('contact_email');
            $table->string('store_logo_path');
            $table->string('store_banner_path')->nullable();
            $table->json('social_links')->nullable();
            $table->string('owner_name');
            $table->string('owner_position');
            $table->string('owner_email');
            $table->string('owner_phone', 20);
            $table->date('owner_birth_date');
            $table->string('government_id_type');
            $table->string('government_id_number');
            $table->date('government_id_expiry_date');
            $table->string('government_id_path');
            $table->enum('status', array_column(MerchantStatus::cases(), 'value'))->default(MerchantStatus::Pending->value);
            $table->timestamps();

            $table->index(['status', 'store_slug']);
            $table->index(['business_category', 'store_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
