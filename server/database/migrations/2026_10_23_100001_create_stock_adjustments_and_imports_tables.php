<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10d: stock adjustment documents (several lines, increase / decrease / set to a counted quantity, submitted and
 * approved before they post to the stock ledger) and the log of data imports (CSV uploads checked, then imported).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->string('reason_code', 30); // InventoryService::ADJUSTMENT_REASONS (lines may override)
            $t->string('reference', 60)->nullable(); // e.g. damage report no., police report, supplier claim
            $t->text('note')->nullable();
            $t->string('status', 12); // draft, submitted, posted, cancelled
            $t->bigInteger('value_minor')->default(0); // net value of the change at average cost (set when posted)
            $t->string('created_by_name', 120);
            $t->uuid('submitted_by')->nullable();
            $t->string('submitted_by_name', 120)->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->string('posted_by_name', 120)->nullable();
            $t->timestamp('posted_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['warehouse_id', 'status']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('line_no');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('mode', 8); // add (increase), remove (decrease), set (set to the counted quantity)
            $t->decimal('qty', 15, 3); // in the line's unit; for "set", the new quantity on hand
            $t->bigInteger('unit_cost_minor')->nullable(); // for increases: the cost of what comes in
            $t->string('batch_no', 60)->nullable();
            $t->date('expires_on')->nullable();
            $t->jsonb('serials')->nullable();
            $t->string('reason_code', 30)->nullable();
            $t->string('note')->nullable();
            $t->decimal('on_hand_before', 15, 3)->nullable(); // base units, when posted
            $t->decimal('change_qty', 15, 3)->nullable(); // base units, signed, when posted
        });

        Schema::create('data_imports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('type', 40); // products, prices, customers, suppliers, opening_stock, stock_adjustment, stock_count
            $t->string('file_name');
            $t->string('stored_path')->nullable();
            $t->string('status', 12); // checked, imported, failed
            $t->jsonb('options')->default('{}');
            $t->uuid('context_id')->nullable(); // the stock count or adjustment imported into
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_ok')->default(0);
            $t->unsignedInteger('rows_error')->default(0);
            $t->jsonb('summary')->default('{}'); // created / updated / unchanged counts
            $t->jsonb('errors')->default('[]'); // [{row, messages}] (first 1000)
            $t->uuid('user_id')->nullable();
            $t->string('user_name', 120);
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
    }
};
