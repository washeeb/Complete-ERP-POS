<?php

use App\Domain\Finance\ChartTemplate;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10f: Manufacturing (owner decision 2026-10-19: production at actual cost). Bills of materials (multi-level, with
 * by-products and a routing of operations at work centres), work orders that issue materials (by hand or backflushed),
 * report output and scrap, charge labour and overhead, pass quality checks, and carry their actual cost into stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A place where work is done (a mixer, a bottling line, the tailoring bench), with its cost per hour.
        Schema::create('work_centres', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('code', 20);
            $t->string('name');
            $t->bigInteger('hourly_rate_minor')->default(0); // labour + overhead charged to production per hour
            $t->decimal('hours_per_day', 5, 2)->default(8); // capacity, for the load report
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        // Recipe for making a product: materials per batch, by-products, and the routing (operations in order).
        Schema::create('boms', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 30);
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete(); // what it makes
            $t->decimal('output_qty', 15, 3)->default(1); // base units one batch makes; materials are per batch
            $t->string('status', 10); // draft, active, archived (one active per product; active ones never change)
            $t->unsignedSmallInteger('revision')->default(1);
            $t->boolean('qc_required')->default(false); // output waits for a quality check before it goes into stock
            $t->jsonb('operations')->default('[]'); // [{seq, name, work_centre_id, setup_minutes, run_minutes (per batch), backflush}]
            $t->text('note')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code', 'revision']);
            $t->index(['product_id', 'status']);
        });

        Schema::create('bom_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('bom_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->string('kind', 12); // material, by_product
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->decimal('qty', 15, 3); // per batch, in the line's unit
            $t->unsignedSmallInteger('scrap_bp')->default(0); // expected loss, added on top (materials)
            $t->boolean('backflush')->default(true); // issued automatically when output is reported
        });

        Schema::create('work_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete(); // materials come from and output goes to
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('bom_id')->constrained()->restrictOnDelete();
            $t->uuid('customer_order_id')->nullable(); // made to order
            $t->uuid('parent_work_order_id')->nullable(); // a sub-assembly made for another work order
            $t->string('status', 12); // draft, released, in_progress, done, cancelled
            $t->decimal('qty_planned', 15, 3);
            $t->decimal('qty_done', 15, 3)->default(0); // good output in stock
            $t->decimal('qty_scrapped', 15, 3)->default(0);
            $t->decimal('qty_awaiting_qc', 15, 3)->default(0);
            $t->boolean('qc_required')->default(false);
            $t->jsonb('operations')->default('[]'); // copied from the BOM at release, with planned and actual minutes
            $t->date('due_on')->nullable();
            $t->bigInteger('material_minor')->default(0); // actual cost so far (from the stock ledger)
            $t->bigInteger('labour_minor')->default(0);
            $t->bigInteger('by_product_minor')->default(0); // value of by-products taken out of the cost
            $t->bigInteger('output_minor')->default(0); // value put into stock
            $t->bigInteger('variance_minor')->default(0); // cost left over at close (to cost of sales)
            $t->text('note')->nullable();
            $t->string('created_by_name', 120);
            $t->string('released_by_name', 120)->nullable();
            $t->timestamp('released_at')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->string('closed_by_name', 120)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('close_reason')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
            $t->index(['product_id', 'status']);
        });

        // The materials and by-products of a work order, snapshotted from the BOM at release (quantities in base units).
        Schema::create('work_order_materials', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('work_order_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->string('kind', 12); // material, by_product
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->decimal('per_unit', 18, 6); // base units per base unit of output (scrap allowance included)
            $t->decimal('planned_qty', 15, 3);
            $t->decimal('issued_qty', 15, 3)->default(0); // materials used (net of returns) or by-products received
            $t->boolean('backflush')->default(true);
        });

        // Cost entries that are not stock movements: labour and overhead charged, and what is left over at close.
        Schema::create('work_order_costs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('work_order_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('kind', 10); // labour, variance
            $t->unsignedSmallInteger('operation_seq')->nullable();
            $t->uuid('work_centre_id')->nullable();
            $t->decimal('minutes', 10, 2)->nullable();
            $t->bigInteger('amount_minor'); // labour: added to work in progress; variance: taken out of it
            $t->string('note')->nullable();
            $t->string('actor_name', 120);
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('occurred_at', 6);
            $t->timestamps();
            $t->index('work_order_id');
        });

        Schema::create('qc_inspections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('work_order_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->decimal('qty_passed', 15, 3);
            $t->decimal('qty_failed', 15, 3);
            $t->jsonb('checks')->default('[]'); // [{check, result, value}]
            $t->text('note')->nullable();
            $t->string('batch_no', 60)->nullable();
            $t->uuid('inspector_id');
            $t->string('inspector_name', 120);
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
        });

        // Work in progress, labour charged to production and production differences (new system accounts).
        foreach (Tenant::all() as $tenant) {
            ChartTemplate::ensure($tenant);
        }
        DB::table('branch_modules')->where('status', 'reserved')->where('module_key', 'manufacturing')->update(['status' => 'enabled', 'updated_at' => now()]);
    }

    public function down(): void
    {
        foreach (['qc_inspections', 'work_order_costs', 'work_order_materials', 'work_orders', 'bom_lines', 'boms', 'work_centres'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
