<?php

use App\Domain\Finance\ChartTemplate;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct purchases (owner request 2026-11-05): a supplier's invoice with no purchase order. See
 * App\Domain\Purchasing\DirectPurchases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_purchases', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->string('status', 20)->default('draft'); // draft, submitted, posted, cancelled
            $t->string('settlement', 20); // paid, credit, consignment
            $t->foreignUuid('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('invoice_no', 60);
            $t->date('invoice_date');
            $t->date('due_on')->nullable();
            $t->boolean('prices_include_tax')->default(false);
            $t->foreignUuid('price_list_id')->nullable()->constrained('price_lists')->nullOnDelete(); // where new selling prices go
            $t->bigInteger('net_minor')->default(0);
            $t->bigInteger('tax_minor')->default(0);
            $t->bigInteger('total_minor')->default(0);
            $t->bigInteger('printed_total_minor')->nullable(); // the total as printed on the invoice
            $t->char('currency_code', 3);
            $t->foreignUuid('account_id')->nullable()->constrained('accounts')->nullOnDelete(); // paid from
            $t->string('method', 20)->nullable();
            $t->string('reference', 80)->nullable();
            $t->jsonb('attachments')->nullable(); // photos or PDFs of the invoice, on the private disk
            $t->text('note')->nullable();
            $t->foreignUuid('goods_receipt_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('supplier_bill_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('supplier_payment_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('created_by_name');
            $t->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('submitted_by_name')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('posted_by_name')->nullable();
            $t->timestampTz('posted_at')->nullable();
            $t->string('reason')->nullable();
            $t->string('origin_node', 40)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
            $t->index(['supplier_id', 'invoice_no']);
            $t->index('branch_id');
            $t->index('warehouse_id');
            $t->index('price_list_id');
            $t->index('account_id');
            $t->index('goods_receipt_id');
            $t->index('supplier_bill_id');
            $t->index('supplier_payment_id');
            $t->index('created_by');
            $t->index('submitted_by');
            $t->index('posted_by');
        });

        Schema::create('direct_purchase_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('direct_purchase_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete(); // the unit bought
            $t->decimal('qty', 18, 3);
            $t->bigInteger('unit_price_minor'); // as on the invoice (with or without tax, as the document says)
            $t->foreignUuid('tax_group_id')->nullable()->constrained('tax_groups')->nullOnDelete();
            $t->bigInteger('unit_cost_minor'); // before tax, per unit bought
            $t->bigInteger('net_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->jsonb('tax_breakdown');
            $t->string('batch_no', 60)->nullable();
            $t->date('expires_on')->nullable();
            $t->string('supplier_sku', 60)->nullable();
            $t->foreignUuid('sell_uom_id')->nullable()->constrained('product_uoms')->nullOnDelete(); // the unit the new price is for
            $t->bigInteger('new_price_minor')->nullable();
            $t->index('direct_purchase_id');
            $t->index('product_id');
            $t->index('product_uom_id');
            $t->index('tax_group_id');
            $t->index('sell_uom_id');
            $t->index('tenant_id');
        });

        // Consignment at an agreed cost per unit, instead of a share of the price.
        Schema::table('products', fn (Blueprint $t) => $t->bigInteger('consignment_cost_minor')->nullable());

        foreach (Tenant::all() as $tenant) {
            ChartTemplate::ensure($tenant); // "Stock received, to be cleared"
        }
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('consignment_cost_minor'));
        Schema::dropIfExists('direct_purchase_lines');
        Schema::dropIfExists('direct_purchases');
    }
};
