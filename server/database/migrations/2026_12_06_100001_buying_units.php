<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buying in packs: each product's usual buying unit (e.g. a box of 100 tablets sold by the tablet or strip), and each
 * supplier's order multiple next to their pack and minimum order. Purchase orders then offer the buying units only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->foreignUuid('buying_uom_id')->nullable()->after('base_uom_id')->constrained('uoms')->nullOnDelete();
            $t->index('buying_uom_id');
        });
        Schema::table('supplier_products', function (Blueprint $t) {
            $t->decimal('order_multiple', 18, 3)->default(0); // in the supplier's unit; 0 = any quantity
        });

        // One-time: the buying unit is the product's largest unit that is bought (box rather than strip or tablet).
        DB::statement('UPDATE products p SET buying_uom_id = (SELECT pu.uom_id FROM product_uoms pu WHERE pu.product_id = p.id AND pu.is_purchasable
            AND NOT pu.is_base ORDER BY pu.factor DESC LIMIT 1) WHERE p.buying_uom_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('supplier_products', fn (Blueprint $t) => $t->dropColumn('order_multiple'));
        Schema::table('products', function (Blueprint $t) {
            $t->dropConstrainedForeignId('buying_uom_id');
        });
    }
};
