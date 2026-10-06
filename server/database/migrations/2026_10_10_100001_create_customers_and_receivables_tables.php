<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6a: customers (CRM basics, consent, credit), B2B order-to-cash (quotation → order → delivery → invoice →
 * payment), credit notes, and the customer ledger (append-only receivables sub-ledger) with a balance projection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 30);
            $t->string('type', 12)->default('individual'); // individual | business
            $t->string('name');
            $t->string('contact_name')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->string('tax_no', 40)->nullable(); // TIN / Ghana Card for invoices
            $t->text('address')->nullable();
            $t->foreignUuid('price_list_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('home_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            // Credit: set at head office only. null = no credit (cash customer).
            $t->bigInteger('credit_limit_minor')->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(0);
            $t->boolean('credit_hold')->default(false); // manual stop
            $t->jsonb('consents')->default('{}'); // {sms: {granted, at, source, by}, email: …, whatsapp: …}
            $t->jsonb('tags')->default('[]');
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
            // Field-level last-writer-wins between nodes: field => "ISO-8601 time|node"
            $t->jsonb('field_stamps')->default('{}');
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
            $t->index(['tenant_id', 'phone']);
        });

        // The receivables sub-ledger. Append-only: + increases what the customer owes, − reduces it.
        Schema::create('customer_ledger', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 20); // invoice | pos_sale | payment | credit_note | opening
            $t->bigInteger('amount_minor');
            $t->char('currency_code', 3);
            $t->string('doc_type', 30);
            $t->uuid('doc_id');
            $t->string('doc_number', 40);
            $t->date('due_on')->nullable();
            $t->string('actor_name');
            $t->string('origin_node', 40);
            $t->timestamp('occurred_at', 6);
            $t->timestamp('received_at', 6);
            $t->index(['customer_id', 'occurred_at']);
            $t->unique(['doc_type', 'doc_id', 'type']);
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION customer_ledger_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION 'customer_ledger is append-only; post a correcting entry instead'; END $$;
            CREATE TRIGGER customer_ledger_no_update BEFORE UPDATE OR DELETE ON customer_ledger FOR EACH ROW EXECUTE FUNCTION customer_ledger_immutable();
            CREATE TRIGGER customer_ledger_no_truncate BEFORE TRUNCATE ON customer_ledger FOR EACH STATEMENT EXECUTE FUNCTION customer_ledger_immutable();
        SQL);

        Schema::create('customer_balances', function (Blueprint $t) {
            $t->foreignUuid('customer_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->bigInteger('balance_minor')->default(0);
            $t->timestamp('updated_at', 6)->nullable();
        });

        // Quotation → order. One document: quote_number while a quotation, number once it is an order.
        Schema::create('customer_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('quote_number', 40)->nullable();
            $t->string('number', 40)->nullable();
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('price_list_id')->constrained()->restrictOnDelete();
            // quotation → (on_hold →) confirmed → partially_delivered → delivered; quotation may be lost; open ones cancelled
            $t->string('status', 20);
            $t->char('currency_code', 3);
            $t->boolean('prices_include_tax');
            $t->bigInteger('net_minor')->default(0);
            $t->bigInteger('tax_minor')->default(0);
            $t->bigInteger('total_minor')->default(0);
            $t->date('valid_until')->nullable(); // quotations
            $t->string('customer_ref', 60)->nullable(); // customer's own PO number
            $t->text('note')->nullable();
            $t->string('hold_reason')->nullable();
            $t->string('released_by_name')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->uuid('created_by')->nullable();
            $t->string('created_by_name');
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('close_reason')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->unique(['tenant_id', 'quote_number']);
            $t->index(['tenant_id', 'status']);
            $t->index('customer_id');
        });

        Schema::create('customer_order_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('customer_order_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('sku', 40);
            $t->string('name', 160);
            $t->string('uom_code', 10);
            $t->decimal('factor', 18, 6);
            $t->decimal('qty', 18, 3);
            $t->decimal('delivered_qty', 18, 3)->default(0);
            $t->bigInteger('unit_price_minor');
            $t->bigInteger('net_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->jsonb('tax_breakdown')->default('[]');
        });

        Schema::create('deliveries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('customer_order_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('status', 12); // posted (immutable)
            $t->string('delivered_to')->nullable();
            $t->string('vehicle', 40)->nullable();
            $t->text('note')->nullable();
            $t->string('delivered_by_name');
            $t->timestamp('delivered_at');
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });

        Schema::create('delivery_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('delivery_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('customer_order_line_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('sku', 40);
            $t->string('name', 160);
            $t->string('uom_code', 10);
            $t->decimal('factor', 18, 6);
            $t->decimal('qty', 18, 3);
        });

        // Invoices (raised when goods are delivered — owner decision) and POS credit sales are what a customer owes.
        Schema::create('invoices', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_order_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('delivery_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status', 12); // posted | credited (fully)
            $t->char('currency_code', 3);
            $t->boolean('prices_include_tax');
            $t->bigInteger('net_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->jsonb('tax_breakdown')->default('[]');
            $t->date('issued_on');
            $t->date('due_on');
            $t->string('customer_name'); // as invoiced
            $t->string('customer_tax_no', 40)->nullable();
            $t->string('customer_ref', 60)->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['customer_id', 'due_on']);
        });

        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('invoice_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('sku', 40);
            $t->string('name', 160);
            $t->string('uom_code', 10);
            $t->decimal('factor', 18, 6);
            $t->decimal('qty', 18, 3);
            $t->bigInteger('unit_price_minor');
            $t->bigInteger('net_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->jsonb('tax_breakdown')->default('[]');
        });

        Schema::create('credit_notes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->nullable()->constrained()->restrictOnDelete(); // goods returned into
            $t->char('currency_code', 3);
            $t->bigInteger('net_minor');
            $t->bigInteger('tax_minor');
            $t->bigInteger('total_minor');
            $t->string('reason');
            $t->boolean('goods_returned')->default(false);
            $t->string('approved_by_name');
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });

        Schema::create('customer_payments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('method', 20); // cash | momo | bank | cheque | card
            $t->string('provider', 20)->nullable();
            $t->string('reference', 80)->nullable();
            $t->char('currency_code', 3);
            $t->bigInteger('amount_minor');
            $t->date('received_on');
            $t->string('received_by_name');
            $t->text('note')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });

        // Which invoices (or till credit sales) a payment or credit note settles. Append-only.
        Schema::create('receivable_allocations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->string('from_type', 20); // customer_payment | credit_note
            $t->uuid('from_id');
            $t->string('to_type', 20); // invoice | sale
            $t->uuid('to_id');
            $t->bigInteger('amount_minor');
            $t->string('origin_node', 40);
            $t->timestamp('created_at', 6);
            $t->index(['to_type', 'to_id']);
            $t->index(['from_type', 'from_id']);
        });

        Schema::table('sales', function (Blueprint $t) {
            $t->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('sale_payments', function (Blueprint $t) {
            $t->string('approved_by_name')->nullable(); // supervisor who allowed a credit sale over the limit
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', fn (Blueprint $t) => $t->dropColumn('approved_by_name'));
        Schema::table('sales', fn (Blueprint $t) => $t->dropConstrainedForeignId('customer_id'));
        foreach (['receivable_allocations', 'customer_payments', 'credit_notes', 'invoice_lines', 'invoices', 'delivery_lines', 'deliveries',
            'customer_order_lines', 'customer_orders', 'customer_balances'] as $t) {
            Schema::dropIfExists($t);
        }
        DB::unprepared('DROP TABLE IF EXISTS customer_ledger; DROP FUNCTION IF EXISTS customer_ledger_immutable();');
        Schema::dropIfExists('customers');
    }
};
