<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase requests: the stock controller asks the buyers to order stock for a store; a buyer turns the request into a
 * draft purchase order (or rejects it with the reason). Separation of duties: who asks does not order or pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('status', 12); // requested → ordered; or rejected / cancelled
            $t->date('needed_by')->nullable();
            $t->text('note')->nullable();
            $t->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('requested_by_name');
            $t->foreignUuid('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->string('closed_by_name')->nullable();
            $t->string('closed_reason')->nullable();
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['tenant_id', 'status']);
            $t->index('warehouse_id');
            $t->index('branch_id');
            $t->index('requested_by');
            $t->index('purchase_order_id');
        });

        Schema::create('purchase_request_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('purchase_request_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('sku', 40);
            $t->string('name', 160);
            $t->string('uom_code', 10);
            $t->decimal('qty', 18, 3);
            $t->string('note')->nullable();
            $t->index('purchase_request_id');
            $t->index('product_id');
            $t->index('product_uom_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_lines');
        Schema::dropIfExists('purchase_requests');
    }
};
