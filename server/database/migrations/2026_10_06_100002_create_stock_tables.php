<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The stock ledger. Append-only: on-hand is the sum of movements. Corrections are new movements.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->decimal('qty_base', 18, 3); // signed, in the product's base unit
            // What the person actually entered, kept for evidence (e.g. "2 CTN" = 48 PC).
            $table->foreignUuid('uom_id')->constrained('uoms')->restrictOnDelete();
            $table->decimal('qty_entered', 18, 3);
            $table->decimal('factor', 18, 6);
            $table->string('type', 30); // opening | adjustment | sale | sale_return | receipt | transfer_out | transfer_in | count
            $table->string('reason')->nullable();
            $table->string('source_type', 40)->nullable(); // sale, adjustment batch…
            $table->uuid('source_id')->nullable();
            $table->bigInteger('unit_cost_minor')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->string('actor_name');
            $table->uuid('device_id')->nullable();
            $table->string('origin_node', 40);
            $table->timestamp('occurred_at', 6); // when it happened (device time for offline work)
            $table->timestamp('received_at', 6); // when this node accepted it
            $table->index(['warehouse_id', 'product_id']);
            $table->index(['source_type', 'source_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION stock_movements_immutable() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'stock_movements is append-only; post a correcting movement instead';
            END $$;
            CREATE TRIGGER stock_movements_no_update BEFORE UPDATE OR DELETE ON stock_movements
                FOR EACH ROW EXECUTE FUNCTION stock_movements_immutable();
            CREATE TRIGGER stock_movements_no_truncate BEFORE TRUNCATE ON stock_movements
                FOR EACH STATEMENT EXECUTE FUNCTION stock_movements_immutable();
        SQL);

        // Fast-read projection of the ledger, updated in the same transaction (row-locked).
        // `php artisan erp:stock-check` proves it equals the sum of movements.
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->decimal('on_hand', 18, 3)->default(0);
            $table->timestamp('updated_at', 6)->nullable();
            $table->primary(['warehouse_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
        DB::unprepared('DROP TABLE IF EXISTS stock_movements; DROP FUNCTION IF EXISTS stock_movements_immutable();');
    }
};
