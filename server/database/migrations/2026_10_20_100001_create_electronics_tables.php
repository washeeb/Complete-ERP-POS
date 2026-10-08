<?php

use App\Domain\Finance\ChartTemplate;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10a: Electronics — serial / IMEI numbers in the stock ledger (each unit known by its number), warranties,
 * repair job cards (with returns to the supplier), and trade-ins taken at the till against a sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('track_serials')->default(false); // every unit is received, moved and sold by its serial / IMEI number
            $t->unsignedSmallInteger('warranty_months')->nullable(); // from the date sold
        });

        Schema::create('serial_numbers', function (Blueprint $t) {
            $t->uuid('id')->primary(); // UUID v5 of company + product + serial, so every server agrees
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->string('serial', 80);
            $t->string('status', 20); // in_stock, in_transit, sold, returned_to_supplier, written_off
            $t->uuid('warehouse_id')->nullable(); // where it is while in stock
            $t->uuid('customer_id')->nullable(); // who bought it
            $t->uuid('sale_id')->nullable();
            $t->timestamp('sold_at', 6)->nullable();
            $t->date('warranty_until')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['product_id', 'serial']);
            $t->index(['tenant_id', 'serial']);
            $t->index(['warehouse_id', 'product_id', 'status']);
        });

        // Which units each stock movement moved (the history of a serial number).
        Schema::create('stock_movement_serials', function (Blueprint $t) {
            $t->uuid('stock_movement_id');
            $t->uuid('serial_number_id');
            $t->primary(['stock_movement_id', 'serial_number_id']);
            $t->index(['serial_number_id']);
        });

        foreach (['sale_lines', 'goods_receipt_lines', 'stock_transfer_lines'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->jsonb('serials')->nullable()); // serial numbers on the line (receipts, transfers, sales)
        }

        Schema::create('repair_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('customer_name', 120);
            $t->string('phone', 20)->nullable();
            $t->uuid('product_id')->nullable();
            $t->uuid('serial_number_id')->nullable();
            $t->string('serial', 80)->nullable();
            $t->string('device', 160); // what it is, as the customer describes it
            $t->text('fault');
            $t->string('accessories')->nullable(); // left with it (charger, case…)
            $t->string('status', 20); // received, diagnosing, waiting_parts, repairing, at_supplier, ready, collected, cancelled
            $t->boolean('in_warranty')->default(false);
            $t->bigInteger('estimate_minor')->nullable();
            $t->string('technician_name')->nullable();
            $t->uuid('supplier_id')->nullable(); // returned to the supplier under warranty (RMA)
            $t->string('rma_reference', 80)->nullable();
            $t->date('promised_on')->nullable();
            $t->timestamp('received_at', 6);
            $t->timestamp('ready_at', 6)->nullable();
            $t->timestamp('collected_at', 6)->nullable();
            $t->uuid('sale_id')->nullable(); // the till sale that charged for it
            $t->jsonb('log')->nullable(); // [{at, by, status, note}]
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['branch_id', 'status']);
        });

        Schema::create('repair_job_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('repair_job_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete(); // a part, or the labour (service) product
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->decimal('qty', 15, 3);
            $t->bigInteger('unit_price_minor'); // what the customer pays (0 under warranty)
        });

        Schema::create('trade_ins', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('sale_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete(); // the used item taken into stock
            $t->string('serial', 80)->nullable();
            $t->string('condition')->nullable();
            $t->bigInteger('value_minor');
            $t->string('approved_by_name')->nullable();
            $t->timestamp('created_at', 6)->nullable();
        });

        Schema::table('sale_lines', fn (Blueprint $t) => $t->uuid('repair_job_id')->nullable());

        foreach (Tenant::all() as $tenant) {
            ChartTemplate::ensure($tenant); // trade-in clearing account
        }
    }

    public function down(): void
    {
        Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn('repair_job_id'));
        foreach (['trade_ins', 'repair_job_lines', 'repair_jobs', 'stock_movement_serials', 'serial_numbers'] as $t) {
            Schema::dropIfExists($t);
        }
        foreach (['sale_lines', 'goods_receipt_lines', 'stock_transfer_lines'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('serials'));
        }
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['track_serials', 'warranty_months']));
    }
};
