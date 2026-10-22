<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned legal documents (Terms and Conditions, Data Privacy Policy) for buyers and merchants,
 * plus the merchant users' registration consents. Customer consents live in
 * sofiacart-website-backend (`customer_legal_consents`), which owns the customer tables.
 *
 * `legal_documents` is shared: sofiacart-website-backend reads published buyer rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('audience', 20);
            $table->string('type', 20);
            // Sequential per audience and type; never reused.
            $table->unsignedInteger('version');
            // Optional label printed in the document itself (e.g. "1.0").
            $table->string('version_label', 50)->nullable();
            $table->date('effective_date')->nullable();
            $table->string('status', 20)->default('draft');
            // 1 while published, NULL otherwise: the unique index below allows only one published
            // version per audience and type (NULLs never collide).
            $table->boolean('is_current')->nullable();
            $table->string('original_filename');
            // Location on the private legal-documents disk. Never exposed through the API.
            $table->string('file_path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->char('checksum', 64);
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('unpublished_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unpublished_at')->nullable();
            $table->timestamps();

            $table->unique(['audience', 'type', 'version']);
            $table->unique(['audience', 'type', 'is_current']);
            $table->index(['audience', 'type', 'status']);
        });

        Schema::create('user_legal_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Versions are never deleted, so the exact document accepted stays resolvable.
            $table->foreignId('legal_document_id')->constrained('legal_documents')->restrictOnDelete();
            $table->string('audience', 20);
            $table->string('type', 20);
            $table->unsignedInteger('version');
            // "accepted" (Terms) or "acknowledged" (Privacy Policy).
            $table->string('action', 20);
            $table->string('context', 50);
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'legal_document_id', 'action']);
            $table->index(['audience', 'type', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_legal_consents');
        Schema::dropIfExists('legal_documents');
    }
};
