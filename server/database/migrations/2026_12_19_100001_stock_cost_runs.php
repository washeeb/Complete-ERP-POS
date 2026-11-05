<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost runs (owner request 2026-11-05): products loaded without a cost get one worked out from their selling price;
 * see App\Domain\Inventory\StockCostRuns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_cost_runs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->string('status', 20)->default('draft'); // draft, submitted, posted, cancelled, reversed
            $t->string('method', 20); // divide (price ÷ 1.4), margin (price less 40%)
            $t->unsignedInteger('value_bp'); // 14000 = ÷ 1.4; 4000 = 40%
            $t->jsonb('overrides')->nullable(); // [{kind: category|brand, id, name, value_bp}]
            $t->string('tax_mode', 20)->default('shelf'); // shelf (what the customer pays), remove_tax
            $t->foreignUuid('price_list_id')->constrained('price_lists')->restrictOnDelete();
            $t->jsonb('scope')->nullable(); // product_ids, category_ids, brand_ids, supplier_ids, warehouse_ids; empty = everything
            $t->boolean('overwrite')->default(false); // replace costs products already have
            $t->boolean('catch_up')->default(true); // add the estimated cost of what was sold at no cost
            $t->date('effective_on');
            $t->foreignUuid('account_id')->nullable()->constrained('accounts')->nullOnDelete(); // null = the usual accounts
            $t->uuid('reverses_id')->nullable(); // the run this one undoes
            $t->bigInteger('stock_value_minor')->default(0);
            $t->bigInteger('catch_up_minor')->default(0);
            $t->unsignedInteger('lines_count')->default(0);
            $t->unsignedInteger('skipped_count')->default(0);
            $t->unsignedInteger('already_costed_count')->default(0);
            $t->char('currency_code', 3);
            $t->string('note')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('created_by_name');
            $t->foreignUuid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('submitted_by_name')->nullable();
            $t->timestampTz('submitted_at')->nullable();
            $t->foreignUuid('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('posted_by_name')->nullable();
            $t->timestampTz('posted_at')->nullable();
            $t->string('reason')->nullable(); // why it was cancelled or undone
            $t->string('origin_node', 40)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
            $t->index('price_list_id');
            $t->index('account_id');
            $t->index('reverses_id');
            $t->index('created_by');
            $t->index('submitted_by');
            $t->index('posted_by');
        });

        Schema::table('stock_cost_runs', fn (Blueprint $t) => $t->foreign('reverses_id')->references('id')->on('stock_cost_runs')->nullOnDelete());

        Schema::create('stock_cost_run_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('stock_cost_run_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('kind', 20); // estimate (no cost), overwrite (replaces a cost), top_up (books short of the cost), skipped
            $t->string('skip_reason')->nullable();
            $t->decimal('on_hand', 16, 3)->default(0);
            $t->decimal('sold_qty', 16, 3)->default(0); // sold at no cost, base units
            $t->decimal('price_minor', 16, 4)->nullable(); // per base unit, as used (after the tax choice)
            $t->unsignedInteger('value_bp')->nullable();
            $t->decimal('old_avg_minor', 16, 4)->nullable();
            $t->bigInteger('new_cost_minor')->nullable(); // per base unit
            $t->bigInteger('value_before_minor')->default(0); // what the books held for this stock
            $t->bigInteger('stock_value_minor')->default(0); // the change in stock value
            $t->bigInteger('catch_up_minor')->default(0);
            $t->timestamps();
            $t->index(['tenant_id', 'product_id']);
            $t->index('stock_cost_run_id');
            $t->index('product_id');
            $t->index('warehouse_id');
            $t->index('branch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_cost_run_lines');
        Schema::dropIfExists('stock_cost_runs');
    }
};
