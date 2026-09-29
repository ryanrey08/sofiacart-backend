<?php

use App\Enums\OrderPaymentStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('payment_status', array_column(OrderPaymentStatus::cases(), 'value'))
                ->default(OrderPaymentStatus::Unpaid->value)
                ->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->enum('status', array_column(PaymentStatus::cases(), 'value'))
                ->default(PaymentStatus::Pending->value)
                ->change();
        });
    }

    public function down(): void
    {
        DB::table('orders')
            ->where('payment_status', OrderPaymentStatus::PartiallyRefunded->value)
            ->update(['payment_status' => OrderPaymentStatus::Paid->value]);

        DB::table('payments')
            ->where('status', PaymentStatus::PartiallyRefunded->value)
            ->update(['status' => PaymentStatus::Completed->value]);

        Schema::table('orders', function (Blueprint $table): void {
            $table->enum('payment_status', [
                OrderPaymentStatus::Unpaid->value,
                OrderPaymentStatus::Paid->value,
                OrderPaymentStatus::Refunded->value,
            ])->default(OrderPaymentStatus::Unpaid->value)->change();
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->enum('status', [
                PaymentStatus::Pending->value,
                PaymentStatus::Completed->value,
                PaymentStatus::Failed->value,
                PaymentStatus::Refunded->value,
            ])->default(PaymentStatus::Pending->value)->change();
        });
    }
};
