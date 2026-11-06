<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Returns (owner request 2026-11-06): reasons, quarantine for what may not go back on sale, and returns from the back office. */
return new class extends Migration
{
    public function up(): void
    {
        // A return from the back office has no till, device or shift.
        Schema::table('sales', function (Blueprint $t) {
            $t->uuid('till_id')->nullable()->change();
            $t->uuid('device_id')->nullable()->change();
            $t->uuid('shift_id')->nullable()->change();
            $t->unsignedBigInteger('device_seq')->nullable()->change();
            $t->string('return_reason_code', 20)->nullable();
        });
        Schema::table('sale_lines', fn (Blueprint $t) => $t->string('returned_to', 12)->nullable()); // shelf, quarantine
        Schema::table('sale_payments', function (Blueprint $t) {
            $t->foreignUuid('account_id')->nullable()->constrained('accounts')->nullOnDelete(); // a refund paid from the office
            $t->index('account_id');
        });
        Schema::table('warehouses', fn (Blueprint $t) => $t->boolean('is_quarantine')->default(false));

        Schema::create('office_returns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->string('status', 20)->default('submitted'); // submitted, posted, cancelled
            $t->foreignUuid('original_sale_id')->constrained('sales')->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->jsonb('lines'); // [{line_id, qty, damaged}]
            $t->string('reason_code', 20);
            $t->string('reason')->nullable();
            $t->string('refund_method', 20); // store_credit, account, money
            $t->foreignUuid('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $t->string('reference', 80)->nullable();
            $t->bigInteger('total_minor');
            $t->char('currency_code', 3);
            $t->foreignUuid('sale_id')->nullable()->constrained('sales')->nullOnDelete(); // the return, once posted
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('created_by_name');
            $t->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('posted_by_name')->nullable();
            $t->timestampTz('posted_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->string('origin_node', 40)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
            $t->index('original_sale_id');
            $t->index('branch_id');
            $t->index('warehouse_id');
            $t->index('account_id');
            $t->index('sale_id');
            $t->index('created_by');
            $t->index('posted_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('office_returns');
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn('is_quarantine'));
        Schema::table('sale_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('account_id'));
        Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn('returned_to'));
        Schema::table('sales', fn (Blueprint $t) => $t->dropColumn('return_reason_code'));
    }
};
