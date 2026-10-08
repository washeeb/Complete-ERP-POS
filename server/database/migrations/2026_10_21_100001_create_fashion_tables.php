<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 10b: Fashion & Boutique — styles with a size × colour matrix of products, seasons and collections, consignment
 * stock (owned by the consignor, settled by a supplier bill for their share of what sold), and alteration jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('styles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 30); // the SKU stem: CODE-COLOUR-SIZE
            $t->string('name');
            $t->foreignUuid('category_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('brand_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('tax_group_id')->constrained()->restrictOnDelete();
            $t->string('season', 30)->nullable(); // e.g. SS26
            $t->string('collection', 60)->nullable();
            $t->jsonb('sizes'); // in display order: ["S", "M", "L"]
            $t->jsonb('colours');
            $t->bigInteger('price_minor')->nullable(); // default list price for new variants
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::table('products', function (Blueprint $t) {
            $t->uuid('style_id')->nullable();
            $t->string('size', 20)->nullable();
            $t->string('colour', 40)->nullable();
            $t->uuid('consignor_id')->nullable(); // supplier who owns the stock (consignment)
            $t->unsignedInteger('consignment_bp')->nullable(); // consignor's share of the net sale price
            $t->index(['style_id']);
        });

        // What each consignment sale owes the consignor (negative for returns), until settled by a supplier bill.
        Schema::create('consignment_sales', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->uuid('sale_id');
            $t->uuid('sale_line_id');
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('consignor_id')->constrained('suppliers')->restrictOnDelete();
            $t->decimal('qty', 15, 3);
            $t->bigInteger('net_minor'); // sale value before tax
            $t->bigInteger('share_minor'); // owed to the consignor
            $t->uuid('supplier_bill_id')->nullable(); // settled on this bill
            $t->timestamp('occurred_at', 6);
            $t->string('origin_node', 20)->nullable();
            $t->timestamp('created_at', 6)->nullable();
            $t->index(['consignor_id', 'supplier_bill_id']);
        });

        Schema::create('alterations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->uuid('customer_id')->nullable();
            $t->string('customer_name', 120);
            $t->string('phone', 20)->nullable();
            $t->string('garment', 160);
            $t->text('work'); // what to do: hem trousers 2 cm, take in waist…
            $t->bigInteger('charge_minor')->default(0); // agreed price (0 = free with purchase)
            $t->string('status', 20); // received, in_progress, ready, collected, cancelled
            $t->date('due_on')->nullable();
            $t->string('tailor_name')->nullable();
            $t->uuid('sale_id')->nullable(); // paid on this sale
            $t->timestamp('received_at', 6);
            $t->timestamp('collected_at', 6)->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['branch_id', 'status']);
        });

        Schema::table('sale_lines', fn (Blueprint $t) => $t->uuid('alteration_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn('alteration_id'));
        Schema::dropIfExists('alterations');
        Schema::dropIfExists('consignment_sales');
        Schema::table('products', function (Blueprint $t) {
            $t->dropIndex(['style_id']);
            $t->dropColumn(['style_id', 'size', 'colour', 'consignor_id', 'consignment_bp']);
        });
        Schema::dropIfExists('styles');
    }
};
