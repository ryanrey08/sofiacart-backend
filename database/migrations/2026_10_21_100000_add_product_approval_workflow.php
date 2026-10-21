<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Product approval: review metadata on `products`, plus `product_change_requests` for edits to
 * products that are already approved (the live row stays unchanged until the edit is approved).
 *
 * Existing rows are backfilled from their current status, never blanket-reset:
 * - `active` products are already live on the storefront; they are grandfathered as approved
 *   (approved_at = their last update) so nothing disappears from the store.
 * - `pending_approval` products get submitted_at so they appear in the review queue.
 * - `draft`, `archived` and `rejected` products were never approved and are left unapproved:
 *   publishing them later goes through review.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('submitted_at')->nullable()->after('status');
            // First approval. While set, the merchant may switch the product between active and archived.
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('rejection_reason')->nullable()->after('reviewed_at');

            $table->index(['status', 'submitted_at']);
        });

        Schema::create('product_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            // Only the customer-facing fields the edit changes, with the requested values.
            $table->json('changes');
            // The approved values of those same fields when the edit was submitted.
            $table->json('original');
            // Newly uploaded gallery images (paths + main index) that replace the gallery on approval.
            $table->json('images')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        DB::table('products')
            ->where('status', 'active')
            ->whereNull('approved_at')
            ->update(['approved_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)')]);

        DB::table('products')
            ->where('status', 'pending_approval')
            ->whereNull('submitted_at')
            ->update(['submitted_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_change_requests');

        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['status', 'submitted_at']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['submitted_at', 'approved_at', 'reviewed_at', 'rejection_reason']);
        });
    }
};
