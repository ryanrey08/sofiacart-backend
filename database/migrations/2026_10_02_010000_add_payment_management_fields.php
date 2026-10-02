<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy `payments.gateway` values that are really payment methods.
     *
     * @var array<string, string>
     */
    protected array $legacyMethods = [
        'card' => 'card',
        'credit_card' => 'card',
        'gcash' => 'gcash',
        'maya' => 'maya',
        'paymaya' => 'maya',
        'bank_transfer' => 'bank_transfer',
        'cod' => 'cod',
        'cash_on_delivery' => 'cod',
        'cash' => 'cash',
        'paypal' => 'paypal',
    ];

    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('payment_status', ['unpaid', 'partially_paid', 'paid', 'partially_refunded', 'refunded'])
                ->default('unpaid')
                ->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'completed', 'failed', 'cancelled', 'expired', 'partially_refunded', 'refunded'])
                ->default('pending')
                ->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('method', 50)->nullable()->after('gateway');
            $table->char('currency', 3)->default('PHP')->after('amount');
            $table->string('gateway_reference')->nullable()->after('reference');
            $table->text('notes')->nullable()->after('paid_at');
            $table->json('attachments')->nullable()->after('notes');
            $table->string('failure_reason')->nullable()->after('attachments');
            $table->timestamp('expires_at')->nullable()->after('failure_reason');
            $table->foreignId('verified_by')->nullable()->after('expires_at')->constrained('users')->nullOnDelete();

            $table->index(['merchant_id', 'method']);
            $table->index('gateway_reference');
            $table->index(['status', 'expires_at']);
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->text('notes')->nullable()->after('reason');
            $table->foreignId('reviewed_by')->nullable()->after('refunded_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->enum('type', ['credit', 'debit', 'payment', 'refund'])->default('credit')->change();
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('refund_id')->nullable()->after('payment_id')->constrained()->nullOnDelete();
        });

        foreach ($this->legacyMethods as $gateway => $method) {
            DB::table('payments')->whereNull('method')->whereRaw('LOWER(gateway) = ?', [$gateway])->update(['method' => $method]);
        }
    }

    public function down(): void
    {
        DB::table('transactions')->where('type', 'payment')->update(['type' => 'credit']);
        DB::table('transactions')->where('type', 'refund')->update(['type' => 'debit']);
        DB::table('payments')->whereIn('status', ['cancelled', 'expired'])->update(['status' => 'failed']);
        DB::table('orders')->where('payment_status', 'partially_paid')->update(['payment_status' => 'unpaid']);

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('refund_id');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->enum('type', ['credit', 'debit'])->default('credit')->change();
        });

        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['notes', 'reviewed_at']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['merchant_id', 'method']);
            $table->dropIndex(['gateway_reference']);
            $table->dropIndex(['status', 'expires_at']);
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['method', 'currency', 'gateway_reference', 'notes', 'attachments', 'failure_reason', 'expires_at']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->enum('status', ['pending', 'completed', 'failed', 'partially_refunded', 'refunded'])
                ->default('pending')
                ->change();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('payment_status', ['unpaid', 'paid', 'partially_refunded', 'refunded'])
                ->default('unpaid')
                ->change();
        });
    }
};
