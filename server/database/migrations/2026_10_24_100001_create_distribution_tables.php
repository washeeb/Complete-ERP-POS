<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10e: Sales & Distribution (owner decisions 2026-10-19 and 2026-10-23): each van is a warehouse with its own till
 * (the rep's phone runs the till app, fully offline); vans are loaded from a warehouse and settled at the end of the day;
 * routes with planned visits; rep targets and commission; orders for delivery; returnable containers (empties).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $t) {
            $t->boolean('is_van')->default(false);
            $t->string('vehicle_reg', 20)->nullable();
            $t->uuid('rep_user_id')->nullable(); // the rep who drives and sells from it
            $t->uuid('home_warehouse_id')->nullable(); // where it is loaded from and unloaded to
        });

        // Returnable containers: a product sold with a deposit container (crate, bottle, gas cylinder).
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('is_container')->default(false); // a returnable empty, sold at its deposit and bought back at the same price
            $t->uuid('deposit_product_id')->nullable(); // the container that goes with each unit
            $t->decimal('deposit_per_unit', 10, 3)->nullable(); // containers per base unit (1 bottle, or 1/24 crate…)
        });

        Schema::create('routes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->uuid('van_warehouse_id')->nullable();
            $t->uuid('rep_user_id')->nullable();
            $t->jsonb('days')->default('[]'); // ISO weekdays served: 1 = Monday … 7 = Sunday
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('route_stops', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('route_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->unique(['route_id', 'customer_id']);
        });

        // A rep's call on a customer: what happened, where (GPS) and when. Append-only, recorded on the phone (offline too).
        Schema::create('visits', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->uuid('route_id')->nullable();
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->uuid('user_id');
            $t->string('user_name', 120);
            $t->uuid('device_id')->nullable();
            $t->string('outcome', 12); // sold, order, no_order, closed
            $t->string('note')->nullable();
            $t->decimal('lat', 9, 6)->nullable();
            $t->decimal('lng', 9, 6)->nullable();
            $t->unsignedInteger('accuracy_m')->nullable();
            $t->uuid('sale_id')->nullable();
            $t->uuid('customer_order_id')->nullable();
            $t->timestamp('occurred_at', 6);
            $t->string('origin_node', 20)->nullable();
            $t->timestamp('created_at', 6)->nullable();
            $t->index(['user_id', 'occurred_at']);
            $t->index(['customer_id', 'occurred_at']);
        });

        Schema::create('rep_targets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->uuid('user_id');
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month');
            $t->bigInteger('target_minor'); // sales before tax
            $t->unsignedInteger('visit_target')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'user_id', 'year', 'month']);
        });

        // End-of-day van settlement: what should be on the van, what is, what goes back to the warehouse, and the cash.
        Schema::create('van_settlements', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('van_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->foreignUuid('return_warehouse_id')->nullable()->constrained('warehouses')->restrictOnDelete();
            $t->uuid('rep_user_id')->nullable();
            $t->date('business_date');
            $t->string('status', 12); // counting, submitted, posted, cancelled
            $t->bigInteger('sales_minor')->default(0); // the rep's sales for the day (all tenders)
            $t->bigInteger('cash_expected_minor')->nullable(); // from the rep's shift(s)
            $t->bigInteger('cash_counted_minor')->nullable();
            $t->bigInteger('stock_variance_minor')->default(0); // value of counted − expected at average cost (posted)
            $t->text('note')->nullable();
            $t->string('started_by_name', 120);
            $t->uuid('submitted_by')->nullable();
            $t->string('submitted_by_name', 120)->nullable();
            $t->string('posted_by_name', 120)->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->uuid('unload_transfer_id')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['van_warehouse_id', 'business_date']);
        });

        Schema::create('van_settlement_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('van_settlement_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->decimal('expected_qty', 15, 3); // base units on the van per the ledger when the settlement started
            $t->decimal('counted_qty', 15, 3)->nullable(); // found on the van
            $t->decimal('unload_qty', 15, 3)->default(0); // going back to the warehouse
        });

        // Released modules switch on where a branch reserved them in the setup wizard (docs/modules.md, rule 5). Earlier
        // releases missed this step, so it is done here for every module released so far.
        DB::table('branch_modules')->where('status', 'reserved')
            ->whereIn('module_key', ['grocery', 'pharmacy', 'restaurant', 'electronics', 'fashion', 'services', 'distribution'])
            ->update(['status' => 'enabled', 'updated_at' => now()]);
    }

    public function down(): void
    {
        foreach (['van_settlement_lines', 'van_settlements', 'rep_targets', 'visits', 'route_stops', 'routes'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['is_container', 'deposit_product_id', 'deposit_per_unit']));
        Schema::table('warehouses', fn (Blueprint $t) => $t->dropColumn(['is_van', 'vehicle_reg', 'rep_user_id', 'home_warehouse_id']));
    }
};
