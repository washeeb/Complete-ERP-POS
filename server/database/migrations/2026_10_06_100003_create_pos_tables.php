<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('till_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('opened_by')->constrained('users')->restrictOnDelete();
            $table->string('opened_by_name');
            $table->char('currency_code', 3);
            $table->bigInteger('opening_float_minor');
            $table->timestamp('opened_at', 6);
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('closed_by_name')->nullable();
            $table->timestamp('closed_at', 6)->nullable();
            $table->bigInteger('expected_cash_minor')->nullable();
            $table->bigInteger('counted_cash_minor')->nullable();
            $table->bigInteger('over_short_minor')->nullable(); // counted − expected
            $table->string('close_note')->nullable();
            $table->string('status', 10)->default('open'); // open | closed
            $table->string('origin_node', 40);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
        // One open shift per till at a time.
        DB::statement("CREATE UNIQUE INDEX shifts_one_open_per_till ON shifts (till_id) WHERE status = 'open'");

        Schema::create('sales', function (Blueprint $table) {
            $table->uuid('id')->primary(); // UUIDv7 made on the till: the idempotency key
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('till_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('device_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('shift_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('device_seq'); // per-device counter → receipt number, never assigned centrally
            $table->string('receipt_no', 40);
            $table->foreignUuid('cashier_id')->constrained('users')->restrictOnDelete();
            $table->string('cashier_name');
            $table->foreignUuid('price_list_id')->constrained()->restrictOnDelete();
            $table->boolean('prices_include_tax');
            $table->char('currency_code', 3);
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->bigInteger('paid_minor');
            $table->bigInteger('change_minor');
            $table->jsonb('tax_breakdown'); // [{code, name, rate_bp, amount_minor}]
            $table->string('status', 12)->default('posted');
            $table->char('payload_hash', 64); // same id + different content = conflict, not a duplicate
            $table->string('origin_node', 40);
            $table->timestamp('occurred_at', 6);
            $table->timestamp('received_at', 6);
            $table->unsignedInteger('version')->default(1);
            $table->unique(['device_id', 'device_seq']);
            $table->unique(['tenant_id', 'receipt_no']);
            $table->index(['branch_id', 'occurred_at']);
        });

        Schema::create('sale_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('sku', 40);
            $table->string('name', 160); // snapshot: renaming a product never rewrites receipts
            $table->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $table->string('uom_code', 10);
            $table->decimal('factor', 18, 6);
            $table->decimal('qty', 18, 3);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_total_minor'); // qty × unit price, rounded half-up
            $table->bigInteger('net_minor');
            $table->bigInteger('tax_minor');
            $table->jsonb('tax_breakdown');
        });

        Schema::create('sale_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('sale_id')->constrained()->restrictOnDelete();
            $table->string('method', 20); // cash | momo | card
            $table->string('provider', 20)->nullable(); // mtn | telecel | at (MoMo); terminal name (card)
            $table->bigInteger('amount_minor'); // applied to the sale
            $table->bigInteger('tendered_minor'); // handed over (cash may exceed amount → change)
            $table->string('reference', 80)->nullable(); // MoMo transaction id / card approval code
            $table->string('payer_phone', 20)->nullable();
            // Manual-reference MoMo/card is "confirmed by cashier"; integrated providers (later) are "verified".
            $table->string('status', 24);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION posted_sales_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'posted sales are immutable; record a return or void instead';
            END $$;
            CREATE TRIGGER sale_lines_no_change BEFORE UPDATE OR DELETE ON sale_lines
                FOR EACH ROW EXECUTE FUNCTION posted_sales_immutable();
            CREATE TRIGGER sale_payments_no_change BEFORE UPDATE OR DELETE ON sale_payments
                FOR EACH ROW EXECUTE FUNCTION posted_sales_immutable();
            CREATE TRIGGER sales_no_delete BEFORE DELETE ON sales
                FOR EACH ROW EXECUTE FUNCTION posted_sales_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS sale_payments; DROP TABLE IF EXISTS sale_lines; DROP TABLE IF EXISTS sales; DROP FUNCTION IF EXISTS posted_sales_immutable();');
        Schema::dropIfExists('shifts');
    }
};
