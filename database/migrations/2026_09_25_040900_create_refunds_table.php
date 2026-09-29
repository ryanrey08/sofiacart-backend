<?php

use App\Enums\RefundStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->decimal('amount', 12, 2);
            $table->text('reason')->nullable();
            $table->enum('status', array_column(RefundStatus::cases(), 'value'))->default(RefundStatus::Pending->value);
            $table->timestamp('refunded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
