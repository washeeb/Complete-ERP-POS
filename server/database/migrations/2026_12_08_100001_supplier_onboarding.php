<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier onboarding (procurement stage 2a): a supplier is approved by a second person before anyone orders from them,
 * with their documents (TIN and VAT certificates, business registration, FDA or Pharmacy Council licence…) on file and
 * watched for expiry; their bank details are verified by a second person before money goes to them (a changed account
 * number is the classic supplier fraud). What kind of supply they make (goods, services, works) sets withholding tax.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $t) {
            $t->string('status', 20)->default('pending'); // pending, approved, blocked
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index('created_by');
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index('approved_by');
            $t->string('approved_by_name')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->string('blocked_reason')->nullable();
            $t->string('supply_kind', 20)->default('goods'); // goods, services, works: the withholding tax rate
            $t->string('bank_name', 80)->nullable();
            $t->string('bank_branch', 80)->nullable();
            $t->string('bank_account_name', 120)->nullable();
            $t->string('bank_account_no', 40)->nullable();
            $t->string('momo_number', 20)->nullable();
            $t->foreignUuid('bank_verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index('bank_verified_by');
            $t->string('bank_verified_by_name')->nullable();
            $t->timestampTz('bank_verified_at')->nullable();
        });
        // One-time: the suppliers already in use stay usable.
        DB::table('suppliers')->update(['status' => 'approved', 'approved_by_name' => 'Existing supplier', 'approved_at' => now()]);

        Schema::create('supplier_documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('supplier_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 40);
            $t->string('number', 80)->nullable();
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable();
            $t->string('file_path')->nullable();
            $t->string('file_name')->nullable();
            $t->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('uploaded_by_name')->nullable();
            $t->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('verified_by_name')->nullable();
            $t->timestampTz('verified_at')->nullable();
            $t->string('alerted', 10)->nullable(); // soon / expired: the last expiry alert sent, so each is sent once
            $t->string('origin_node', 40)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('tenant_id');
            $t->index('supplier_id');
            $t->index('uploaded_by');
            $t->index('verified_by');
            $t->index(['expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_documents');
        Schema::table('suppliers', function (Blueprint $t) {
            foreach (['created_by', 'approved_by', 'bank_verified_by'] as $fk) {
                $t->dropConstrainedForeignId($fk);
            }
            $t->dropColumn(['status', 'approved_by_name', 'approved_at', 'blocked_reason', 'supply_kind', 'bank_name', 'bank_branch', 'bank_account_name',
                'bank_account_no', 'momo_number', 'bank_verified_by_name', 'bank_verified_at']);
        });
    }
};
