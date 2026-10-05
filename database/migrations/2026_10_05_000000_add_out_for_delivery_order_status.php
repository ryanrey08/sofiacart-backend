<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `out_for_delivery` to orders.status (processing -> out_for_delivery -> completed).
 * Additive: existing values and rows are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'processing', 'out_for_delivery', 'completed', 'cancelled'])
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        if (DB::table('orders')->where('status', 'out_for_delivery')->exists()) {
            throw new RuntimeException('Orders are out for delivery; move them to another status before rolling back.');
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'processing', 'completed', 'cancelled'])
                ->default('pending')
                ->change();
        });
    }
};
