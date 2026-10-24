<?php

use App\Domain\Finance\ChartTemplate;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NHIS at the till (owner request 2026-10-24): a medicine's NHIS price (what the scheme pays per base unit), the
 * till tender "NHIS" (the scheme's part of a sale, owed by the NHIA), and claims: the NHIS parts not yet claimed, put
 * together per branch, sent to the NHIA, and settled with what it paid (the rest written off as not paid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->bigInteger('nhis_price_minor')->nullable(); // per base unit; null = not on the NHIS medicines list
        });

        Schema::create('nhis_claims', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('branch_id')->index()->constrained()->restrictOnDelete();
            $t->string('number', 40); // NHC-HQ-000012
            $t->date('period_from');
            $t->date('period_to');
            $t->string('status', 12)->default('open'); // open | submitted | settled
            $t->bigInteger('claimed_minor')->default(0);
            $t->bigInteger('paid_minor')->nullable();
            $t->bigInteger('rejected_minor')->nullable(); // claimed − paid: written off
            $t->string('reference', 80)->nullable(); // the NHIA's claim reference
            $t->string('prepared_by_name', 120);
            $t->string('submitted_by_name', 120)->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->date('paid_on')->nullable();
            $t->string('paid_method', 12)->nullable(); // bank | momo | cheque | cash
            $t->string('settled_by_name', 120)->nullable();
            $t->string('note')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('nhis_claim_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('nhis_claim_id')->index()->constrained()->cascadeOnDelete();
            $t->foreignUuid('sale_payment_id')->unique()->constrained()->restrictOnDelete(); // claimed once
            $t->foreignUuid('sale_id')->index()->constrained()->restrictOnDelete();
            $t->bigInteger('amount_minor'); // negative for a return
            $t->timestamps();
        });

        foreach (Tenant::all() as $tenant) {
            ChartTemplate::ensure($tenant); // "Due from NHIS" and "NHIS claims not paid"
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nhis_claim_lines');
        Schema::dropIfExists('nhis_claims');
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('nhis_price_minor'));
    }
};
