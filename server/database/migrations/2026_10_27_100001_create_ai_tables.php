<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11: AI analytics and copilot (owner decisions 2026-10-27: no cloud AI for now — off by default, local model
 * optional; names masked and personal data kept local if cloud is ever used; copilot for managers and up; invoice
 * scanning, shop descriptions, cash-flow forecast and pricing advice).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Every prompt and answer (masked as sent), for the audit trail and the monthly cloud budget. Append-only.
        Schema::create('ai_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('user_id')->nullable();
            $t->string('user_name', 120);
            $t->string('feature', 30); // copilot, invoice_scan, product_text
            $t->string('provider', 12); // ollama, claude, fake
            $t->string('model', 80);
            $t->text('prompt'); // as sent (masked)
            $t->text('response')->nullable();
            $t->jsonb('tools')->default('[]'); // tools the model called
            $t->unsignedInteger('tokens_in')->default(0);
            $t->unsignedInteger('tokens_out')->default(0);
            $t->unsignedBigInteger('cost_micro_usd')->default(0);
            $t->string('status', 12); // ok, error, refused
            $t->string('error')->nullable();
            $t->unsignedInteger('duration_ms')->default(0);
            $t->timestamp('created_at', 6);
            $t->index(['tenant_id', 'created_at']);
        });

        // Insights that need someone to look: loss prevention, margins, stock-outs, expiry, dead stock, churn.
        Schema::create('ai_alerts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('branch_id')->nullable();
            $t->string('kind', 30);
            $t->string('severity', 8); // info, warning, high
            $t->string('subject_type', 30)->nullable(); // product, user, shift, customer
            $t->uuid('subject_id')->nullable();
            $t->string('title');
            $t->text('detail')->nullable();
            $t->jsonb('data')->default('{}');
            $t->string('fingerprint', 120); // one open alert per kind and subject
            $t->string('status', 12)->default('open'); // open, dismissed, resolved
            $t->string('handled_by_name', 120)->nullable();
            $t->string('handled_note')->nullable();
            $t->timestamp('handled_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status', 'kind']);
            $t->index(['tenant_id', 'fingerprint']);
        });

        // A supplier invoice read by the AI: what it found, and the bill entered from it after review.
        Schema::create('ai_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20); // supplier_invoice
            $t->string('file_path');
            $t->string('file_name');
            $t->string('status', 12); // read, failed, used
            $t->jsonb('extracted')->default('{}');
            $t->jsonb('form')->default('{}'); // the bill form, pre-filled and matched
            $t->string('error')->nullable();
            $t->uuid('supplier_bill_id')->nullable();
            $t->string('created_by_name', 120);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_documents');
        Schema::dropIfExists('ai_alerts');
        Schema::dropIfExists('ai_requests');
    }
};
