<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: suppliers and purchasing (PO → approval → GRN), warehouse bins and put-away, transfers with
 * in-transit, stock counts, reorder rules, weighted-average cost, and per-node document numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Human document numbers: one sequence per node and document type ("PO-HQ-000001"), so nodes never collide.
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('node', 40);
            $t->string('doc_type', 10);
            $t->unsignedBigInteger('last_value')->default(0);
            $t->primary(['tenant_id', 'node', 'doc_type']);
        });

        Schema::create('suppliers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->string('contact_name')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('tax_no', 40)->nullable();
            $t->text('address')->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(0);
            $t->char('currency_code', 3);
            $t->unsignedSmallInteger('lead_time_days')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        // What a supplier sells us: the unit we buy in (e.g. carton of 24), their code, last cost, minimum order.
        Schema::create('supplier_products', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('supplier_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('supplier_sku', 60)->nullable();
            $t->bigInteger('last_cost_minor')->nullable(); // per purchase unit, before tax
            $t->decimal('min_order_qty', 18, 3)->default(0); // in the purchase unit
            $t->boolean('is_preferred')->default(false);
            $t->unique(['supplier_id', 'product_uom_id']);
            $t->index('product_id');
        });

        Schema::table('warehouses', function (Blueprint $t) {
            $t->boolean('use_bins')->default(false);
        });

        Schema::create('bins', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('code', 30); // e.g. A-01-02 (aisle-bay-level); printed as a barcode on the shelf
            $t->string('name')->nullable();
            $t->string('kind', 12)->default('storage'); // receiving | storage
            $t->unsignedInteger('pick_order')->default(0); // sales and transfers take stock from bins in this order
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['warehouse_id', 'code']);
        });

        // Movements in a bin-managed warehouse name their bin; the warehouse total is unchanged by moving between bins.
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->foreignUuid('bin_id')->nullable()->constrained('bins')->restrictOnDelete();
        });

        Schema::create('stock_bin_levels', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('bin_id')->constrained('bins')->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->decimal('on_hand', 18, 3)->default(0);
            $t->timestamp('updated_at', 6)->nullable();
            $t->primary(['bin_id', 'product_id']);
            $t->index(['warehouse_id', 'product_id']);
        });

        // Weighted-average cost per base unit, in minor units with 4 extra decimals (owner decision 2026-10-09).
        Schema::table('stock_levels', function (Blueprint $t) {
            $t->decimal('avg_cost_minor', 20, 4)->nullable();
        });

        // Reorder rules: keep stock between min and max (in base units) per warehouse.
        Schema::create('reorder_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->decimal('min_qty', 18, 3);
            $t->decimal('max_qty', 18, 3);
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['warehouse_id', 'product_id']);
        });

        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            // draft → pending_approval → approved → partially_received → received; or cancelled; approved/partial may be closed short
            $t->string('status', 20);
            $t->char('currency_code', 3);
            $t->bigInteger('net_minor')->default(0);
            $t->bigInteger('tax_minor')->default(0);
            $t->bigInteger('total_minor')->default(0);
            $t->date('expected_on')->nullable();
            $t->text('note')->nullable();
            $t->uuid('created_by')->nullable();
            $t->string('created_by_name');
            $t->timestamp('submitted_at')->nullable();
            $t->uuid('approved_by')->nullable();
            $t->string('approved_by_name')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->string('rejected_reason')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('close_reason')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('sku', 40);
            $t->string('name', 160);
            $t->string('uom_code', 10);
            $t->decimal('factor', 18, 6);
            $t->decimal('qty', 18, 3);
            $t->decimal('received_qty', 18, 3)->default(0); // in the line's unit
            $t->bigInteger('unit_cost_minor'); // before tax, per line unit
            $t->bigInteger('net_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->jsonb('tax_breakdown')->default('[]');
        });

        // Goods received notes. Posted GRNs are immutable; a mistake is corrected by reversing the GRN.
        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('status', 12); // held (over-delivery waiting for a manager) | posted | reversed
            $t->string('supplier_ref', 60)->nullable(); // supplier's delivery note / invoice number
            $t->text('note')->nullable();
            $t->char('currency_code', 3);
            $t->bigInteger('net_minor')->default(0);
            $t->uuid('received_by')->nullable();
            $t->string('received_by_name');
            $t->timestamp('received_at');
            $t->uuid('approved_by')->nullable(); // over-delivery approval
            $t->string('approved_by_name')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->timestamp('reversed_at')->nullable();
            $t->string('reversed_by_name')->nullable();
            $t->string('reversal_reason')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index('purchase_order_id');
        });

        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('goods_receipt_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('purchase_order_line_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('sku', 40);
            $t->string('name', 160);
            $t->string('uom_code', 10);
            $t->decimal('factor', 18, 6);
            $t->decimal('qty', 18, 3);
            $t->bigInteger('unit_cost_minor'); // before tax, per line unit
            $t->bigInteger('net_minor');
            $t->foreignUuid('bin_id')->nullable()->constrained('bins')->restrictOnDelete();
        });

        Schema::create('stock_transfers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->foreignUuid('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->foreignUuid('from_branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignUuid('to_branch_id')->constrained('branches')->restrictOnDelete();
            $t->string('status', 12); // draft → in_transit → received; draft may be cancelled
            $t->boolean('has_discrepancy')->default(false);
            $t->text('note')->nullable();
            $t->string('created_by_name');
            $t->timestamp('dispatched_at')->nullable();
            $t->string('dispatched_by_name')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->string('received_by_name')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('stock_transfer_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('sku', 40);
            $t->string('name', 160);
            $t->string('uom_code', 10);
            $t->decimal('factor', 18, 6);
            $t->decimal('qty', 18, 3); // sent, in the line unit
            $t->decimal('received_qty', 18, 3)->nullable();
            $t->decimal('unit_cost_minor', 20, 4)->nullable(); // average cost per base unit at dispatch
            $t->foreignUuid('to_bin_id')->nullable()->constrained('bins')->restrictOnDelete();
        });

        Schema::create('stock_counts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('bin_id')->nullable()->constrained('bins')->restrictOnDelete(); // count one bin only
            $t->string('status', 12); // counting → submitted → posted; or cancelled
            $t->boolean('blind')->default(true); // counters do not see the expected quantity
            $t->text('note')->nullable();
            $t->timestamp('started_at', 6);
            $t->string('started_by_name');
            $t->timestamp('submitted_at')->nullable();
            $t->uuid('submitted_by')->nullable();
            $t->string('submitted_by_name')->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->string('posted_by_name')->nullable();
            $t->bigInteger('variance_value_minor')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });

        Schema::create('stock_count_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('stock_count_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('bin_id')->nullable()->constrained('bins')->restrictOnDelete();
            $t->decimal('expected_qty', 18, 3); // base units, as at the start of the count
            $t->decimal('counted_qty', 18, 3)->nullable();
            $t->string('counted_by_name')->nullable();
            $t->timestamp('counted_at')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX stock_count_lines_unique ON stock_count_lines (stock_count_id, product_id, COALESCE(bin_id, \'00000000-0000-0000-0000-000000000000\'))');
    }

    public function down(): void
    {
        foreach (['stock_count_lines', 'stock_counts', 'stock_transfer_lines', 'stock_transfers', 'goods_receipt_lines', 'goods_receipts',
            'purchase_order_lines', 'purchase_orders', 'reorder_rules', 'stock_bin_levels'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('stock_levels', fn (Blueprint $t) => $t->dropColumn('avg_cost_minor'));
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('bin_id'));
        Schema::dropIfExists('bins');
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn('use_bins'));
        foreach (['supplier_products', 'suppliers', 'document_sequences'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
