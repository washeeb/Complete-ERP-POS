<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 13 (owner decisions 2026-10-29): automatic encrypted backups to a USB / second drive, S3-compatible storage,
 * Google Drive or SFTP, with tested restores; GRA E-VAT reporting with three modes (each sale as it happens, a daily
 * batch, or invoices only) chosen in the settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Where backups are copied. Node-local: each server backs itself up; credentials encrypted with its key.
        Schema::create('backup_destinations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 80);
            $t->string('type', 10); // folder, s3, gdrive, sftp
            $t->text('config'); // encrypted JSON (paths, bucket, keys…)
            $t->unsignedSmallInteger('keep')->default(14); // newest copies kept there
            $t->boolean('is_active')->default(true);
            $t->timestamp('last_ok_at')->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
        });

        Schema::create('backup_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('file_name');
            $t->string('status', 10); // running, ok, partial, failed
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->string('sha256', 64)->nullable();
            $t->jsonb('destinations')->default('[]'); // [{id, name, ok, error}]
            $t->string('error')->nullable();
            $t->string('started_by', 120);
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('verify_result')->nullable();
            $t->index('started_at');
        });

        // Sales and invoices reported to GRA (E-VAT): what was sent, GRA's answer and the code printed on the receipt.
        Schema::create('fiscal_submissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('doc_type', 20); // sale, invoice, credit_note
            $t->uuid('doc_id');
            $t->string('doc_number', 60);
            $t->uuid('branch_id')->nullable();
            $t->date('doc_date');
            $t->string('mode', 12); // realtime, daily, invoices
            $t->string('status', 12); // pending, submitted, failed, rejected
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('batch_id', 60)->nullable();
            $t->string('gra_reference', 120)->nullable(); // GRA's invoice / signature number
            $t->text('qr_code')->nullable(); // what the receipt's GRA QR code holds
            $t->jsonb('payload')->default('{}');
            $t->jsonb('response')->default('{}');
            $t->string('error')->nullable();
            $t->timestamps();
            $t->unique(['doc_type', 'doc_id']);
            $t->index(['tenant_id', 'status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_submissions');
        Schema::dropIfExists('backup_runs');
        Schema::dropIfExists('backup_destinations');
    }
};
