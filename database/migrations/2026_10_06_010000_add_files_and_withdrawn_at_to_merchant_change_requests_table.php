<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: replacement logo/banner/document uploads awaiting approval (kept on the private disk
 * until approved) and the time a merchant withdrew a request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_change_requests', function (Blueprint $table): void {
            // { "store_logo": { "path": "...", "name": "...", "mime": "...", "size": 123 }, ... }
            $table->json('files')->nullable()->after('original');
            $table->timestamp('withdrawn_at')->nullable()->after('rejection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('merchant_change_requests', function (Blueprint $table): void {
            $table->dropColumn(['files', 'withdrawn_at']);
        });
    }
};
