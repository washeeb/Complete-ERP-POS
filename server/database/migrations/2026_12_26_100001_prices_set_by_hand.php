<?php

use App\Domain\Pricing\PriceRepair;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A price set on purpose (product form, price file, a direct purchase's new selling price) is not second-guessed by
 * the pricing rules until a newer delivery brings a new cost (owner 2026-11-10: every direct purchase's selling price
 * was undone — the rules saw the same delivery's cost, suggested cost × 1.40, and approving the list put the old
 * figures back; Cytotec went from 10.00 to 5.00).
 *
 * set_by_hand_at: when the price was last set on purpose; null when the rules set it. set_by_hand_cost_line_id: the
 * product's latest delivery line at that moment (the cost already in), so a newer delivery lets the rules work again.
 * Existing prices: on purpose unless the rules set them last. Then the damage is repaired once: where a posted direct
 * purchase set a price and a rule later replaced it citing that same delivery, the purchase's price is put back
 * (audited); suggestions that would undo a price set on purpose after their cost are dismissed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $t) {
            $t->timestamp('set_by_hand_at')->nullable();
            // A remembered reference, not a database link: prices reach branch servers apart from deliveries, in any order.
            $t->uuid('set_by_hand_cost_line_id')->nullable()->index();
        });

        DB::statement(<<<'SQL'
            UPDATE prices p SET set_by_hand_at = p.updated_at,
                set_by_hand_cost_line_id = (SELECT gl.id FROM goods_receipt_lines gl JOIN goods_receipts g ON g.id = gl.goods_receipt_id
                    WHERE gl.product_id = p.product_id AND g.tenant_id = p.tenant_id AND g.status = 'posted' AND g.posted_at <= p.updated_at
                    ORDER BY g.posted_at DESC, g.id DESC, gl.line_no DESC LIMIT 1)
            SQL);
        DB::statement(<<<'SQL'
            UPDATE prices p SET set_by_hand_at = NULL
            FROM (SELECT DISTINCT ON (subject_id) subject_id, action FROM audit_events
                  WHERE action LIKE 'price.%' AND subject_id IS NOT NULL ORDER BY subject_id, seq DESC) last
            WHERE last.subject_id = p.id AND last.action = 'price.set_by_rule'
            SQL);

        $repair = app(PriceRepair::class);
        foreach (DB::table('tenants')->pluck('id') as $tenant) {
            $repair->restoreDirectPurchasePrices($tenant);
            $repair->dismissSuggestionsOverHandPrices($tenant);
        }
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $t) {
            $t->dropColumn(['set_by_hand_at', 'set_by_hand_cost_line_id']);
        });
    }
};
