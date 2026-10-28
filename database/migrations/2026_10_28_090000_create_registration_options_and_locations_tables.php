<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin-managed lists for merchant registration: business types, categories, government ID
 * types, and provinces with their cities/municipalities.
 *
 * Merchants keep storing the chosen names as text (merchants.business_type, .province, .city, ...),
 * so renaming or deactivating an option never changes existing stores. The lists are seeded with
 * the values the registration form had built in; the official PSGC provinces and cities are loaded
 * separately (locations:import-psgc).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_options', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40);
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'name']);
            $table->index(['type', 'is_active', 'sort_order']);
        });

        Schema::create('provinces', function (Blueprint $table): void {
            $table->id();
            // Philippine Standard Geographic Code (10 digits) when imported from PSA data.
            $table->string('psgc_code', 10)->nullable()->unique();
            $table->string('name');
            $table->string('region')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('province_id')->constrained()->cascadeOnDelete();
            $table->string('psgc_code', 10)->nullable()->unique();
            $table->string('name');
            // "city" or "municipality" when known.
            $table->string('kind', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['province_id', 'name']);
            $table->index(['province_id', 'is_active']);
        });

        $now = now();
        $options = [
            'business_type' => ['Sole Proprietorship', 'Partnership', 'Corporation', 'Cooperative'],
            'category' => ['Beauty', 'Fashion', 'Home', 'Food', 'Electronics', 'Lifestyle'],
            'government_id_type' => ['Passport', "Driver's License", 'UMID', 'PhilSys ID', 'Postal ID'],
        ];
        foreach ($options as $type => $names) {
            DB::table('registration_options')->insertOrIgnore(array_map(fn (string $name, int $i) => [
                'type' => $type, 'name' => $name, 'sort_order' => $i, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ], $names, array_keys($names)));
        }

        DB::table('provinces')->insertOrIgnore(array_map(fn (string $name) => [
            'name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
        ], ['Metro Manila', 'Cebu', 'Davao del Sur', 'Laguna', 'Pampanga']));
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('provinces');
        Schema::dropIfExists('registration_options');
    }
};
