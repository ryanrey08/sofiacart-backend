<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table): void {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            $table->string('type', 32)->nullable()->after('user_id');
            $table->string('reference_type', 64)->nullable()->after('resulting_stock');
            $table->string('reference_number', 100)->nullable()->after('reference_type');
            $table->string('supplier')->nullable()->after('reference_number');
            $table->date('reference_date')->nullable()->after('supplier');

            $table->index(['merchant_id', 'type']);
            $table->index('reference_number');
        });

        $this->backfillExistingLogs();
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table): void {
            $table->dropIndex(['merchant_id', 'type']);
            $table->dropIndex(['reference_number']);
            $table->dropConstrainedForeignId('product_variant_id');
            $table->dropColumn(['type', 'reference_type', 'reference_number', 'supplier', 'reference_date']);
        });
    }

    /**
     * Classify logs written before movement types existed, using the reason/notes formats
     * produced by InventoryService and ReturnRequestsController.
     */
    protected function backfillExistingLogs(): void
    {
        $variantIds = DB::table('product_variants')->pluck('id')->flip();

        DB::table('inventory_logs')->whereNull('type')->orderBy('id')->chunkById(500, function ($logs) use ($variantIds): void {
            foreach ($logs as $log) {
                $attributes = ['type' => 'adjustment'];

                if (preg_match('/^Order created: (.+)$/', $log->reason, $matches)) {
                    $attributes = ['type' => 'sale', 'reference_type' => 'order', 'reference_number' => $matches[1]];
                } elseif (preg_match('/^Order cancelled: (.+)$/', $log->reason, $matches)) {
                    $attributes = ['type' => 'cancellation', 'reference_type' => 'order', 'reference_number' => $matches[1]];
                } elseif (preg_match('/^Return processed: (.+)$/', $log->reason, $matches)) {
                    $attributes = ['type' => 'return', 'reference_type' => 'return_request', 'reference_number' => $matches[1]];
                }

                if (preg_match('/variant #(\d+)/', (string) $log->notes, $matches) && $variantIds->has((int) $matches[1])) {
                    $attributes['product_variant_id'] = (int) $matches[1];
                }

                DB::table('inventory_logs')->where('id', $log->id)->update($attributes);
            }
        });
    }
};
