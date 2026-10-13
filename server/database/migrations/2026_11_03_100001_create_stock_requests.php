<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Industries part B (owner decisions 2026-10-12): a branch asks another for stock. Between sister branches of one
 * industry the supplier simply sends it; between two industries both sides approve first, the goods move at cost, and
 * the supplier says which of its own products it sends for each item asked for (separate catalogues).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('from_warehouse_id')->constrained('warehouses')->restrictOnDelete(); // the supplier
            $t->foreignUuid('to_warehouse_id')->constrained('warehouses')->restrictOnDelete(); // the branch asking
            $t->foreignUuid('from_branch_id')->constrained('branches')->restrictOnDelete();
            $t->foreignUuid('to_branch_id')->constrained('branches')->restrictOnDelete();
            $t->boolean('cross_industry')->default(false);
            $t->string('status', 12); // requested → approved (between industries) → supplied → received; or rejected / cancelled
            $t->text('note')->nullable();
            $t->string('requested_by_name');
            $t->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('requester_approved_at')->nullable();
            $t->string('requester_approved_by_name')->nullable();
            $t->timestamp('supplier_approved_at')->nullable();
            $t->string('supplier_approved_by_name')->nullable();
            $t->foreignUuid('stock_transfer_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('supplied_at')->nullable();
            $t->string('supplied_by_name')->nullable();
            $t->timestamp('closed_at')->nullable(); // rejected, cancelled or received
            $t->string('closed_reason')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
        });

        Schema::create('stock_request_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('stock_request_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete(); // from the asking branch's catalogue
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('sku', 40);
            $t->string('name', 160);
            $t->string('uom_code', 10);
            $t->decimal('factor', 18, 6);
            $t->decimal('qty', 18, 3); // asked for, in the line unit
            $t->decimal('supplied_qty', 18, 3)->nullable(); // sent, in the asked-for unit
        });

        // A transfer line may land as another product of the receiving industry (same item, separate catalogue).
        Schema::table('stock_transfers', function (Blueprint $t) {
            $t->foreignUuid('stock_request_id')->nullable()->constrained()->nullOnDelete();
        });
        Schema::table('stock_transfer_lines', function (Blueprint $t) {
            $t->foreignUuid('to_product_id')->nullable()->constrained('products')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('to_product_id'));
        Schema::table('stock_transfers', fn (Blueprint $t) => $t->dropConstrainedForeignId('stock_request_id'));
        Schema::dropIfExists('stock_request_lines');
        Schema::dropIfExists('stock_requests');
    }
};
