<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'approved', 'rejected', 'processing', 'processed', 'failed', 'cancelled'])
                ->default('pending')
                ->change();
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->foreignId('return_request_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->string('payout_reference')->nullable()->after('reference');
            $table->string('failure_reason')->nullable()->after('notes');
            $table->boolean('cancel_order')->default(false)->after('failure_reason');
            $table->foreignId('requested_by')->nullable()->after('cancel_order')->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable()->after('requested_by');

            $table->unique(['merchant_id', 'idempotency_key']);
            $table->index(['payment_id', 'status']);
        });

        Schema::create('refund_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('amount', 12, 2);
            $table->unique(['refund_id', 'order_item_id']);
        });

        Schema::create('refund_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('refund_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['refund_id', 'created_at']);
        });

        // Existing refunds start their history with their current status.
        DB::table('refunds')->orderBy('id')->each(function (object $refund): void {
            DB::table('refund_status_histories')->insert([
                'refund_id' => $refund->id,
                'from_status' => null,
                'to_status' => $refund->status,
                'user_id' => $refund->reviewed_by ?? null,
                'notes' => 'Recorded before refund history tracking.',
                'created_at' => $refund->refunded_at ?? $refund->created_at ?? now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_status_histories');
        Schema::dropIfExists('refund_items');

        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropUnique(['merchant_id', 'idempotency_key']);
            $table->dropIndex(['payment_id', 'status']);
            $table->dropConstrainedForeignId('return_request_id');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropColumn(['payout_reference', 'failure_reason', 'cancel_order', 'idempotency_key']);
        });

        DB::table('refunds')->where('status', 'processing')->update(['status' => 'approved']);
        DB::table('refunds')->whereIn('status', ['failed', 'cancelled'])->update(['status' => 'rejected']);

        Schema::table('refunds', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'approved', 'rejected', 'processed'])->default('pending')->change();
        });
    }
};
