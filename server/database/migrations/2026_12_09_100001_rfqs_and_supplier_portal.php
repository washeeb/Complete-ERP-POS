<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Procurement stage 3. Requests for quotation: the buyer asks several suppliers for prices on the same list, each
 * supplier answers on their own link (sealed: no one sees the prices until the closing time or until the buyer closes
 * it), the answers are compared side by side (price, delivery, credit terms, past performance) and awarded, whole or
 * line by line, into draft purchase orders. The supplier portal: one secret link per supplier to see and quote on
 * requests, confirm orders with a delivery date, say goods are on the way, send invoices and see what is owed and paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->string('title', 160);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('purchase_request_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20); // open, closed, awarded, cancelled
            $t->timestampTz('closes_at');
            $t->date('needed_by')->nullable();
            $t->text('terms')->nullable(); // what the suppliers are told: delivery place, payment terms, packaging…
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('created_by_name');
            $t->timestampTz('closed_at')->nullable();
            $t->string('closed_by_name')->nullable();
            $t->foreignUuid('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('awarded_by_name')->nullable();
            $t->timestampTz('awarded_at')->nullable();
            $t->string('award_note', 500)->nullable(); // why, when a line did not go to the cheapest price
            $t->string('cancelled_reason')->nullable();
            $t->string('origin_node', 40)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index('branch_id');
            $t->index('warehouse_id');
            $t->index('purchase_request_id');
            $t->index('created_by');
            $t->index('awarded_by');
            $t->index(['tenant_id', 'status']);
        });
        Schema::create('rfq_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('rfq_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('line_no');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('sku', 64);
            $t->string('name');
            $t->string('uom_code', 20);
            $t->decimal('qty', 18, 3);
            $t->string('note')->nullable();
            $t->index('rfq_id');
            $t->index('product_id');
            $t->index('product_uom_id');
        });
        Schema::create('rfq_suppliers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('rfq_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->char('token', 40)->unique(); // the supplier's secret link to this request
            $t->string('status', 20)->default('invited'); // invited, quoted, declined
            $t->timestampTz('sent_at')->nullable();
            $t->string('sent_via', 20)->nullable();
            $t->string('sent_to', 160)->nullable();
            $t->timestampTz('viewed_at')->nullable();
            $t->timestampTz('quoted_at')->nullable();
            $t->string('quoted_by_name')->nullable(); // the supplier's person, or "Entered by …" for a quote typed in by staff
            $t->unsignedInteger('delivery_days')->nullable();
            $t->unsignedInteger('payment_days')->nullable(); // credit: days after delivery to pay
            $t->date('valid_until')->nullable();
            $t->string('note', 500)->nullable();
            $t->bigInteger('total_minor')->nullable(); // before VAT, for the quantities offered
            $t->foreignUuid('purchase_order_id')->nullable()->constrained()->nullOnDelete(); // when awarded
            $t->unique(['rfq_id', 'supplier_id']);
            $t->index('rfq_id');
            $t->index('supplier_id');
            $t->index('purchase_order_id');
        });
        Schema::create('rfq_quote_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('rfq_supplier_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('rfq_line_id')->constrained()->cascadeOnDelete();
            $t->bigInteger('unit_cost_minor'); // per unit asked for, before VAT
            $t->decimal('qty', 18, 3); // what they can supply (up to the quantity asked)
            $t->string('note')->nullable();
            $t->boolean('awarded')->default(false);
            $t->unique(['rfq_supplier_id', 'rfq_line_id']);
            $t->index('rfq_supplier_id');
            $t->index('rfq_line_id');
        });

        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->foreignUuid('rfq_id')->nullable()->constrained()->nullOnDelete();
            $t->index('rfq_id');
            $t->timestampTz('supplier_confirmed_at')->nullable();
            $t->date('supplier_promised_on')->nullable();
            $t->string('supplier_reply', 500)->nullable();
            $t->timestampTz('dispatched_at')->nullable();
            $t->string('dispatch_ref', 120)->nullable(); // their delivery note / waybill number, vehicle
        });
        Schema::table('suppliers', function (Blueprint $t) {
            $t->char('portal_token', 40)->nullable()->unique();
            $t->timestampTz('portal_sent_at')->nullable();
        });

        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('invoice_no', 60);
            $t->date('invoice_date');
            $t->bigInteger('amount_minor');
            $t->char('currency_code', 3);
            $t->string('file_path')->nullable();
            $t->string('file_name')->nullable();
            $t->string('note', 500)->nullable();
            $t->string('sent_by_name', 120);
            $t->string('status', 20)->default('received'); // received, entered, rejected
            $t->foreignUuid('supplier_bill_id')->nullable()->constrained()->nullOnDelete();
            $t->string('handled_by_name')->nullable();
            $t->timestampTz('handled_at')->nullable();
            $t->string('reject_reason')->nullable();
            $t->string('origin_node', 40)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index('tenant_id');
            $t->index('supplier_id');
            $t->index('purchase_order_id');
            $t->index('supplier_bill_id');
            $t->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
        Schema::table('suppliers', function (Blueprint $t) {
            $t->dropUnique(['portal_token']);
            $t->dropColumn(['portal_token', 'portal_sent_at']);
        });
        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('rfq_id');
            $t->dropColumn(['supplier_confirmed_at', 'supplier_promised_on', 'supplier_reply', 'dispatched_at', 'dispatch_ref']);
        });
        Schema::dropIfExists('rfq_quote_lines');
        Schema::dropIfExists('rfq_suppliers');
        Schema::dropIfExists('rfq_lines');
        Schema::dropIfExists('rfqs');
    }
};
