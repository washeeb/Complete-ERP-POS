<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Minimum stock for every product: a company default (Settings → Stock, in the buying unit, max twice the minimum)
// unless the product has its own figure in that store. Where a level came from is kept (own figure, accepted or
// automatic smart level, or back to the default), and the weekly smart levels worked out from sales wait to be accepted.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reorder_rules', function (Blueprint $t) {
            $t->string('source', 10)->default('own'); // own | smart | default (follows the company default again)
        });
        Schema::create('reorder_level_suggestions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->index()->constrained()->restrictOnDelete();
            $t->foreignUuid('warehouse_id')->index()->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->index()->constrained()->restrictOnDelete();
            $t->decimal('min_qty', 18, 3); // base units
            $t->decimal('max_qty', 18, 3);
            $t->decimal('avg_daily', 18, 3);
            $t->unsignedSmallInteger('lead_days');
            $t->timestamp('computed_at');
            $t->unique(['warehouse_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reorder_level_suggestions');
        Schema::table('reorder_rules', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
