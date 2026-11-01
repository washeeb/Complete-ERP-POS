<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement stage 4.
 *  - Buying what is not stock (services, repairs, stationery, equipment): a purchase request or order line can name an
 *    expense (or asset) account instead of a product. Such a line is confirmed as delivered on the order (no goods
 *    received note, nothing enters stock) and billed against the order line. Each line, request and order can carry a
 *    department (the cost centre), which goes on into the books.
 *  - Budget check: the order's lines against the year's budget for the branch and account, less what is already spent
 *    and already committed on open orders. The order remembers what was seen when it was submitted.
 *  - Contracts and blanket orders (purchase agreements): prices agreed with a supplier for a period, optionally with a
 *    quantity per item and a total value; orders made against it take the agreed price and count against the caps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_agreements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->string('kind', 20); // contract (agreed prices), blanket (agreed prices + quantities / value to draw down)
            $t->string('title', 160);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->bigInteger('value_cap_minor')->nullable(); // most that may be ordered on it, before VAT
            $t->char('currency_code', 3);
            $t->string('status', 20); // draft, active, closed
            $t->text('terms')->nullable();
            $t->string('file_path')->nullable();
            $t->string('file_name')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('created_by_name');
            $t->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('approved_by_name')->nullable();
            $t->timestampTz('approved_at')->nullable();
            $t->string('closed_reason')->nullable();
            $t->string('alerted', 10)->nullable(); // soon / ended: the last end-date notice sent
            $t->string('origin_node', 40)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index('supplier_id');
            $t->index('created_by');
            $t->index('approved_by');
            $t->index(['tenant_id', 'status', 'ends_on']);
        });
        Schema::create('purchase_agreement_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_agreement_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('product_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('account_id')->nullable()->constrained()->restrictOnDelete(); // a service or non-stock item
            $t->string('name', 160);
            $t->string('uom_code', 10);
            $t->bigInteger('unit_cost_minor'); // agreed, before VAT
            $t->decimal('max_qty', 18, 3)->nullable(); // blanket: most that may be ordered
            $t->string('note')->nullable();
            $t->index('purchase_agreement_id');
            $t->index('product_id');
            $t->index('product_uom_id');
            $t->index('account_id');
        });

        Schema::table('purchase_request_lines', function (Blueprint $t) {
            $t->uuid('product_id')->nullable()->change();
            $t->uuid('product_uom_id')->nullable()->change();
            $t->string('sku', 40)->nullable()->change();
            $t->foreignUuid('account_id')->nullable()->constrained()->restrictOnDelete();
            $t->index('account_id');
            $t->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $t->index('department_id');
            $t->bigInteger('est_unit_cost_minor')->nullable(); // the requester's estimate, for the budget check
        });
        Schema::table('purchase_requests', function (Blueprint $t) {
            $t->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $t->index('department_id');
        });
        Schema::table('purchase_order_lines', function (Blueprint $t) {
            $t->uuid('product_id')->nullable()->change();
            $t->uuid('product_uom_id')->nullable()->change();
            $t->string('sku', 40)->nullable()->change();
            $t->decimal('factor', 18, 6)->nullable()->change();
            $t->foreignUuid('account_id')->nullable()->constrained()->restrictOnDelete();
            $t->index('account_id');
            $t->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $t->index('department_id');
            $t->foreignUuid('agreement_line_id')->nullable()->constrained('purchase_agreement_lines')->nullOnDelete();
            $t->index('agreement_line_id');
        });
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->foreignUuid('agreement_id')->nullable()->constrained('purchase_agreements')->nullOnDelete();
            $t->index('agreement_id');
            $t->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $t->index('department_id');
            $t->string('budget_note', 500)->nullable(); // over budget when submitted: what was seen
            $t->boolean('over_budget')->default(false);
        });
        Schema::table('supplier_bill_lines', function (Blueprint $t) {
            $t->foreignUuid('purchase_order_line_id')->nullable()->constrained()->restrictOnDelete();
            $t->index('purchase_order_line_id');
            $t->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $t->index('department_id');
        });
        Schema::table('journal_lines', function (Blueprint $t) {
            $t->foreignUuid('department_id')->nullable()->constrained()->nullOnDelete();
            $t->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('journal_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('department_id'));
        Schema::table('supplier_bill_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('purchase_order_line_id');
            $t->dropConstrainedForeignId('department_id');
        });
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('agreement_id');
            $t->dropConstrainedForeignId('department_id');
            $t->dropColumn(['budget_note', 'over_budget']);
        });
        Schema::table('purchase_order_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('account_id');
            $t->dropConstrainedForeignId('department_id');
            $t->dropConstrainedForeignId('agreement_line_id');
        });
        Schema::table('purchase_requests', fn (Blueprint $t) => $t->dropConstrainedForeignId('department_id'));
        Schema::table('purchase_request_lines', function (Blueprint $t) {
            $t->dropConstrainedForeignId('account_id');
            $t->dropConstrainedForeignId('department_id');
            $t->dropColumn('est_unit_cost_minor');
        });
        Schema::dropIfExists('purchase_agreement_lines');
        Schema::dropIfExists('purchase_agreements');
    }
};
