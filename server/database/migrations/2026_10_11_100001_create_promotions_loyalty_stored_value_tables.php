<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6b: promotions (item and basket, coupons, time windows), discounts on sales, returns, loyalty points,
 * gift cards and store credit. Points and stored value are append-only ledgers with balance projections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('name');
            // item: percent | amount_off | buy_x_get_y | bundle — basket: threshold | coupon
            $t->string('type', 20);
            $t->jsonb('product_ids')->default('[]'); // empty with empty categories = everything
            $t->jsonb('category_ids')->default('[]');
            $t->unsignedInteger('value_bp')->default(0); // % off in basis points (also "get" discount for buy X get Y; 10000 = free)
            $t->bigInteger('value_minor')->default(0); // amount off (per unit, or off the basket) / bundle price
            $t->unsignedSmallInteger('buy_qty')->default(0);
            $t->unsignedSmallInteger('get_qty')->default(0);
            $t->unsignedSmallInteger('bundle_qty')->default(0);
            $t->bigInteger('min_spend_minor')->default(0);
            $t->string('coupon_code', 30)->nullable();
            $t->jsonb('price_list_ids')->default('[]'); // empty = all lists
            $t->jsonb('branch_ids')->default('[]'); // empty = all branches
            $t->jsonb('days')->default('[]'); // ISO weekdays 1–7; empty = every day
            $t->time('time_from')->nullable(); // happy hour window (branch local time)
            $t->time('time_to')->nullable();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->index(['tenant_id', 'is_active']);
        });
        DB::statement('CREATE UNIQUE INDEX promotions_coupon_unique ON promotions (tenant_id, upper(coupon_code)) WHERE coupon_code IS NOT NULL');

        Schema::table('sales', function (Blueprint $t) {
            $t->string('kind', 10)->default('sale'); // sale | return
            $t->foreignUuid('original_sale_id')->nullable()->constrained('sales')->restrictOnDelete();
            $t->bigInteger('discount_minor')->default(0);
            $t->string('coupon_code', 30)->nullable();
            $t->unsignedInteger('sale_discount_bp')->default(0);
            $t->string('discount_approved_by_name')->nullable();
            $t->integer('points_earned')->default(0);
            $t->string('return_reason')->nullable();
        });
        Schema::table('sale_lines', function (Blueprint $t) {
            $t->bigInteger('list_total_minor')->nullable(); // before any discount
            $t->bigInteger('discount_minor')->default(0);
            $t->jsonb('promotions')->default('[]'); // [{id, name, saving_minor}]
            $t->jsonb('manual_discount')->nullable(); // {bp} or {price_minor}
            $t->foreignUuid('original_line_id')->nullable(); // returns
        });

        // Customer payments taken at a till count in that shift's cash.
        Schema::table('customer_payments', function (Blueprint $t) {
            $t->foreignUuid('shift_id')->nullable()->constrained()->nullOnDelete();
        });

        // Loyalty points (1 point per GHS 1 spent; 100 points = GHS 1 — owner decision 2026-10-10)
        Schema::create('loyalty_ledger', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('customer_id')->constrained()->restrictOnDelete();
            $t->string('type', 12); // earn | redeem | expire | reverse | adjust
            $t->integer('points');
            $t->string('doc_type', 30);
            $t->uuid('doc_id');
            $t->string('doc_number', 40);
            $t->date('expires_on')->nullable(); // earn lots
            $t->string('actor_name');
            $t->string('origin_node', 40);
            $t->timestamp('occurred_at', 6);
            $t->timestamp('received_at', 6);
            $t->unique(['doc_type', 'doc_id', 'type']);
            $t->index(['customer_id', 'occurred_at']);
        });
        Schema::create('loyalty_balances', function (Blueprint $t) {
            $t->foreignUuid('customer_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->integer('points')->default(0);
            $t->timestamp('updated_at', 6)->nullable();
        });

        // Gift cards and store credit: an account with a code, and an append-only ledger.
        Schema::create('stored_values', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->string('kind', 12); // gift_card | store_credit
            $t->string('code', 30); // printed on the card / receipt
            $t->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();
            $t->char('currency_code', 3);
            $t->string('status', 10)->default('active'); // active | void
            $t->timestamp('last_used_at', 6)->nullable(); // expiry is 12 months after last use (owner decision)
            $t->string('origin_node', 40);
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->unique(['tenant_id', 'code']);
        });
        Schema::create('stored_value_ledger', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->foreignUuid('stored_value_id')->constrained()->restrictOnDelete();
            $t->string('type', 12); // issue | redeem | refund | expire
            $t->bigInteger('amount_minor');
            $t->string('doc_type', 30);
            $t->uuid('doc_id');
            $t->string('doc_number', 40);
            $t->string('actor_name');
            $t->string('origin_node', 40);
            $t->timestamp('occurred_at', 6);
            $t->timestamp('received_at', 6);
            $t->unique(['stored_value_id', 'doc_type', 'doc_id', 'type']);
        });
        Schema::create('stored_value_balances', function (Blueprint $t) {
            $t->foreignUuid('stored_value_id')->primary()->constrained()->restrictOnDelete();
            $t->foreignUuid('tenant_id')->constrained()->restrictOnDelete();
            $t->bigInteger('balance_minor')->default(0);
            $t->timestamp('updated_at', 6)->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN RAISE EXCEPTION '% is append-only; post a correcting entry instead', TG_TABLE_NAME; END $$;
            CREATE TRIGGER loyalty_ledger_append_only BEFORE UPDATE OR DELETE ON loyalty_ledger FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
            CREATE TRIGGER stored_value_ledger_append_only BEFORE UPDATE OR DELETE ON stored_value_ledger FOR EACH ROW EXECUTE FUNCTION ledger_append_only();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS stored_value_balances, stored_value_ledger, stored_values, loyalty_balances, loyalty_ledger; DROP FUNCTION IF EXISTS ledger_append_only();');
        if (Schema::hasColumn('customer_payments', 'shift_id')) {
            Schema::table('customer_payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('shift_id'));
        }
        Schema::table('sale_lines', fn (Blueprint $t) => $t->dropColumn(['list_total_minor', 'discount_minor', 'promotions', 'manual_discount', 'original_line_id']));
        Schema::table('sales', function (Blueprint $t) {
            $t->dropConstrainedForeignId('original_sale_id');
            $t->dropColumn(['kind', 'discount_minor', 'coupon_code', 'sale_discount_bp', 'discount_approved_by_name', 'points_earned', 'return_reason']);
        });
        Schema::dropIfExists('promotions');
    }
};
