<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `source_key` identifies the event that produced a ledger row ("payment:12",
     * "refund:7", later a gateway event id). The unique index guarantees a repeated
     * verification or gateway callback can never write a second row for the same event.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('source_key', 100)->nullable()->unique()->after('reference');
        });

        DB::table('transactions')->whereNotNull('refund_id')->orderBy('id')->each(function (object $transaction): void {
            $this->assignSourceKey($transaction->id, 'refund:'.$transaction->refund_id);
        });

        DB::table('transactions')
            ->where('type', 'payment')
            ->whereNotNull('payment_id')
            ->whereNull('refund_id')
            ->orderBy('id')
            ->each(function (object $transaction): void {
                $this->assignSourceKey($transaction->id, 'payment:'.$transaction->payment_id);
            });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropUnique(['source_key']);
            $table->dropColumn('source_key');
        });
    }

    /**
     * Only the oldest row per event receives the key; any pre-existing duplicates keep NULL.
     */
    protected function assignSourceKey(int $transactionId, string $sourceKey): void
    {
        if (! DB::table('transactions')->where('source_key', $sourceKey)->exists()) {
            DB::table('transactions')->where('id', $transactionId)->update(['source_key' => $sourceKey]);
        }
    }
};
