<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchant profile edits are stored here until a Super Admin reviews them, so unapproved values
 * never touch the live `merchants` row. Purely additive: no existing table or column is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            // Only the fields the merchant changed, with the requested values.
            $table->json('changes');
            // The approved values of those same fields when the request was submitted.
            $table->json('original');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_change_requests');
    }
};
