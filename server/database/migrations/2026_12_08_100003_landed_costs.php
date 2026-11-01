<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Landed costs (procurement stage 2c): freight, duty, clearing and insurance on purchases, spread over the deliveries they
 * belong to, so a product's cost is what it really cost to get it on the shelf. The charge is entered as a bill to
 * "Freight, duty and clearing to spread"; the landed cost moves it into stock (for what is still on hand) and cost of
 * sales (for what has already been sold). Posted once; never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landed_costs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('method', 20); // value, quantity
            $t->bigInteger('total_minor');
            $t->bigInteger('to_stock_minor');
            $t->bigInteger('to_cogs_minor');
            $t->char('currency_code', 3);
            $t->string('note')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('created_by_name');
            $t->timestampTz('posted_at');
            $t->string('origin_node', 40)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index('branch_id');
            $t->index('created_by');
        });
        Schema::create('landed_cost_charges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('landed_cost_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20); // freight, duty, clearing, insurance, other
            $t->string('description');
            $t->bigInteger('amount_minor');
            $t->index('landed_cost_id');
        });
        Schema::create('landed_cost_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('landed_cost_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('goods_receipt_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('goods_receipt_line_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->bigInteger('allocated_minor');
            $t->bigInteger('to_stock_minor');
            $t->bigInteger('to_cogs_minor');
            $t->decimal('avg_before', 18, 4)->nullable();
            $t->decimal('avg_after', 18, 4)->nullable();
            $t->index('landed_cost_id');
            $t->index('goods_receipt_id');
            $t->index('goods_receipt_line_id');
            $t->index('product_id');
            $t->index('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landed_cost_lines');
        Schema::dropIfExists('landed_cost_charges');
        Schema::dropIfExists('landed_costs');
    }
};
