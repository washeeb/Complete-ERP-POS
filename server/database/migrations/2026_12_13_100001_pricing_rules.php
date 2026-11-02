<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic selling prices (pricing rules): a price worked out from the latest cost of a product (with freight, duty
 * and clearing spread onto it) — cost × 1.40, a markup or margin, or cost plus an amount — for a whole company, a
 * category or one product, on a price list, rounded, as the shelf price (tax inside) or before tax. When a delivery
 * changes the cost, the price follows: by default rises at once and drops after a manager's approval
 * (price_suggestions = "prices to review").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('scope', 10);                  // company | category | product
            $t->foreignUuid('category_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignUuid('price_list_id')->constrained()->restrictOnDelete();
            $t->string('method', 10);                 // multiply | markup | margin | plus | manual
            $t->unsignedInteger('value_bp')->nullable(); // multiply 14000 = ×1.40; markup/margin 2500 = 25 %
            $t->bigInteger('amount_minor')->nullable();  // plus: cost + this
            $t->string('tax_mode', 10)->default('inclusive'); // inclusive: the result is the shelf price; exclusive: taxes added on top
            $t->unsignedInteger('round_minor')->default(10);   // round to this many pesewas (1 = none)
            $t->string('round_mode', 8)->default('nearest');   // nearest | up
            $t->string('on_change', 12)->default('rise_auto'); // rise_auto (drops for approval) | auto | approve
            $t->boolean('is_active')->default(true);
            $t->string('updated_by_name')->nullable();
            $t->string('origin_node', 40)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['tenant_id', 'scope']);
            $t->index('category_id');
            $t->index('product_id');
            $t->index('price_list_id');
        });

        Schema::create('price_suggestions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('product_uom_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('price_list_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('pricing_rule_id')->nullable()->constrained()->nullOnDelete();
            $t->bigInteger('cost_minor');              // the unit's cost the suggestion was worked out from
            $t->bigInteger('current_minor')->nullable();
            $t->bigInteger('suggested_minor');
            $t->string('reason', 120);
            $t->string('status', 10)->default('pending'); // pending | applied | dismissed
            $t->string('decided_by_name')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'status']);
            $t->index('product_id');
            $t->index('product_uom_id');
            $t->index('price_list_id');
            $t->index('pricing_rule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_suggestions');
        Schema::dropIfExists('pricing_rules');
    }
};
