<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9a: batch / expiry tracking in the stock ledger (used by Grocery and Pharmacy), Grocery's scale PLU codes and
 * near-expiry markdowns. A batch's id is derived from (company, product, batch number) so every server agrees on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('track_batches')->default(false); // every movement names a batch; sold oldest expiry first
            $t->string('plu', 5)->nullable(); // item code printed in weighted-barcode labels by the scale
            $t->unique(['tenant_id', 'plu']);
        });

        Schema::create('stock_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->string('batch_no', 60);
            $t->date('expires_on')->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['product_id', 'batch_no']);
            $t->index(['product_id', 'expires_on']);
        });

        Schema::table('stock_movements', function (Blueprint $t) {
            $t->uuid('batch_id')->nullable();
            $t->index(['batch_id']);
        });

        Schema::create('stock_batch_levels', function (Blueprint $t) {
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->decimal('on_hand', 15, 3)->default(0);
            $t->timestamp('updated_at')->nullable();
            $t->primary(['warehouse_id', 'batch_id']);
        });

        Schema::table('goods_receipt_lines', function (Blueprint $t) {
            $t->string('batch_no', 60)->nullable();
            $t->date('expires_on')->nullable();
        });

        Schema::create('markdowns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('code', 13); // EAN-13 printed on the sticker
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $t->unsignedInteger('percent_bp');
            $t->bigInteger('list_price_minor');
            $t->bigInteger('price_minor'); // the marked-down unit price the till charges
            $t->date('valid_until');
            $t->unsignedInteger('labels')->default(1);
            $t->string('status', 10)->default('active'); // active, withdrawn
            $t->string('created_by_name');
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::table('sale_lines', function (Blueprint $t) {
            $t->uuid('markdown_id')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_lines', 'markdown_id')) {
            Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn('markdown_id'));
        }
        Schema::dropIfExists('markdowns');
        if (Schema::hasColumn('goods_receipt_lines', 'batch_no')) {
            Schema::table('goods_receipt_lines', fn (Blueprint $t) => $t->dropColumn(['batch_no', 'expires_on']));
        }
        Schema::dropIfExists('stock_batch_levels');
        if (Schema::hasColumn('stock_movements', 'batch_id')) {
            Schema::table('stock_movements', fn (Blueprint $t) => $t->dropColumn('batch_id'));
        }
        Schema::dropIfExists('stock_batches');
        if (Schema::hasColumn('products', 'plu')) {
            Schema::table('products', function (Blueprint $t) {
                $t->dropUnique(['tenant_id', 'plu']);
                $t->dropColumn(['track_batches', 'plu']);
            });
        }
    }
};
