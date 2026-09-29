<?php

use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->enum('type', array_column(TransactionType::cases(), 'value'))->default(TransactionType::Credit->value);
            $table->enum('status', array_column(TransactionStatus::cases(), 'value'))->default(TransactionStatus::Pending->value);
            $table->decimal('amount', 12, 2);
            $table->text('description')->nullable();
            $table->timestamp('transacted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'type']);
            $table->index('transacted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
