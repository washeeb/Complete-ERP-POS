<?php

use App\Domain\Finance\ChartTemplate;
use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9c: Restaurant — floor plan and tables, kitchen stations, modifiers, recipes (ingredients deducted when the bill
 * is paid), dining orders shared by tills, waiter tablets and kitchen screens, tips and service charge on sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('code', 20); // matches products.station (KITCHEN, BAR, GRILL…)
            $t->string('name');
            $t->boolean('auto_print')->default(false); // no screen: the kitchen device prints each ticket instead
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['branch_id', 'code']);
        });

        Schema::create('dining_tables', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('code', 20);
            $t->string('area', 60)->nullable(); // Inside, Terrace, Bar…
            $t->unsignedSmallInteger('seats')->default(4);
            $t->unsignedSmallInteger('pos_x')->default(0); // floor plan grid position
            $t->unsignedSmallInteger('pos_y')->default(0);
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['branch_id', 'code']);
        });

        Schema::create('modifier_groups', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name'); // "Cooking", "Extras", "Sides"
            $t->unsignedSmallInteger('min_choices')->default(0); // 1 = must choose
            $t->unsignedSmallInteger('max_choices')->default(1);
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });

        Schema::create('modifier_options', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('modifier_group_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->foreignUuid('product_id')->nullable()->constrained()->restrictOnDelete(); // priced extra: charged as that product
            $t->unsignedSmallInteger('sort')->default(0);
        });

        Schema::table('products', function (Blueprint $t) {
            $t->string('station', 20)->nullable(); // kitchen station code that prepares it
            $t->jsonb('modifier_group_ids')->nullable();
        });

        Schema::create('recipe_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('product_id')->constrained()->cascadeOnDelete(); // the dish
            $t->foreignUuid('ingredient_id')->constrained('products')->restrictOnDelete();
            $t->foreignUuid('ingredient_uom_id')->constrained('product_uoms')->restrictOnDelete();
            $t->decimal('qty', 15, 3); // per one base unit of the dish
            $t->unique(['product_id', 'ingredient_id']);
        });

        Schema::create('dining_orders', function (Blueprint $t) {
            $t->uuid('id')->primary(); // till-generated
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('number', 40);
            $t->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $t->string('kind', 10); // table, tab (bar tab by name), counter, delivery
            $t->foreignUuid('dining_table_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('tab_name', 60)->nullable();
            $t->unsignedSmallInteger('covers')->default(0);
            $t->foreignUuid('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->uuid('waiter_id')->nullable();
            $t->string('waiter_name');
            $t->string('status', 10)->default('open'); // open, closed, void
            $t->string('note')->nullable();
            $t->timestamp('opened_at', 6);
            $t->timestamp('closed_at', 6)->nullable();
            $t->string('origin_node', 20)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'number']);
            $t->index(['branch_id', 'status']);
        });
        // One open order per table (a second guest party at the same table is a second order on another table code).
        DB::statement("CREATE UNIQUE INDEX dining_orders_one_open_per_table ON dining_orders (dining_table_id) WHERE status = 'open' AND dining_table_id IS NOT NULL");

        Schema::create('dining_order_items', function (Blueprint $t) {
            $t->uuid('id')->primary(); // till-generated
            $t->foreignUuid('dining_order_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->decimal('qty', 15, 3);
            $t->jsonb('modifiers')->nullable(); // [{group, option_id, name, product_id}]
            $t->string('note')->nullable();
            $t->unsignedSmallInteger('course')->default(0); // 0 = as soon as sent; 1, 2, 3… fired by the waiter
            $t->unsignedSmallInteger('seat')->nullable();
            $t->string('station', 20)->nullable();
            $t->string('status', 10); // held, fired, ready, served, void
            $t->uuid('sale_id')->nullable(); // paid on this sale
            $t->string('added_by_name');
            $t->timestamp('added_at', 6);
            $t->timestamp('fired_at', 6)->nullable();
            $t->timestamp('ready_at', 6)->nullable();
            $t->timestamp('served_at', 6)->nullable();
            $t->string('void_reason')->nullable();
            $t->string('voided_by_name')->nullable();
            $t->index(['station', 'status']);
        });

        Schema::table('sales', function (Blueprint $t) {
            $t->uuid('dining_order_id')->nullable();
            $t->bigInteger('tip_minor')->default(0); // paid on top of the total; owed to tip_user_id
            $t->uuid('tip_user_id')->nullable();
            $t->index(['dining_order_id']);
        });
        Schema::table('payslips', function (Blueprint $t) {
            $t->bigInteger('tips_minor')->default(0); // the month's tips, paid out with the salary (taxable)
        });
        Schema::table('sale_lines', function (Blueprint $t) {
            $t->uuid('dining_item_id')->nullable();
            $t->string('note')->nullable(); // modifiers / kitchen note printed under the line
            $t->boolean('is_service_charge')->default(false);
        });

        // Tips owed to staff (new system account; existing charts get it here, new companies from the template).
        foreach (Tenant::all() as $tenant) {
            ChartTemplate::ensure($tenant);
        }
    }

    public function down(): void
    {
        Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn(['dining_item_id', 'note', 'is_service_charge']));
        Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn('tips_minor'));
        Schema::table('sales', function (Blueprint $t) {
            $t->dropIndex(['dining_order_id']);
            $t->dropColumn(['dining_order_id', 'tip_minor', 'tip_user_id']);
        });
        foreach (['dining_order_items', 'dining_orders', 'recipe_lines', 'modifier_options', 'modifier_groups', 'dining_tables', 'kitchen_stations'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['station', 'modifier_group_ids']));
    }
};
