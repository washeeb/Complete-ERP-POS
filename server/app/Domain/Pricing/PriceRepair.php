<?php

namespace App\Domain\Pricing;

use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Models\Price;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * One-time repair (migration 2026_12_26_100001): direct purchases' selling prices that the pricing rules undid (owner
 * 2026-11-10: Cytotec set to 10.00 on DP-HQ-000003, put back to 5.00 by an approved rule suggestion citing the same
 * delivery). Kept here, not in the migration, so it is tested.
 */
final class PriceRepair
{
    /** Put back each direct purchase's price that a rule later replaced from that purchase's own delivery. */
    public function restoreDirectPurchasePrices(string $tenantId): int
    {
        app(TenantContext::class)->set($tenantId);
        $actor = Actor::system($tenantId, 'Price repair (direct purchase prices undone by the pricing rules)');
        $lines = DB::table('direct_purchase_lines as l')->join('direct_purchases as d', 'd.id', '=', 'l.direct_purchase_id')
            ->leftJoin('goods_receipts as g', 'g.id', '=', 'd.goods_receipt_id')
            ->where('d.tenant_id', $tenantId)->where('d.status', 'posted')->whereNotNull('l.new_price_minor')->whereNotNull('l.sell_uom_id')->whereNotNull('d.price_list_id')
            ->orderBy('d.posted_at')->get(['d.number', 'd.posted_at', 'd.goods_receipt_id', 'd.price_list_id', 'g.number as grn', 'l.product_id', 'l.sell_uom_id', 'l.new_price_minor']);
        $restored = 0;
        foreach ($lines->keyBy(fn ($l) => $l->price_list_id.'|'.$l->sell_uom_id) as $l) { // the latest purchase per price
            $price = Price::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('price_list_id', $l->price_list_id)->where('product_uom_id', $l->sell_uom_id)
                ->where('min_qty', 1)->whereNull('starts_at')->whereNull('ends_at')->first();
            if (! $price || $price->amount_minor === (int) $l->new_price_minor) {
                continue;
            }
            $last = DB::table('audit_events')->where('subject_id', $price->id)->where('action', 'like', 'price.%')->orderByDesc('seq')->first(['action', 'reason', 'occurred_at']);
            // Only what the rules did after the purchase, from that purchase's own delivery: anything else was someone's choice.
            if (! $last || $last->action !== 'price.set_by_rule' || strtotime((string) $last->occurred_at) < strtotime((string) $l->posted_at)
                || ! $l->grn || ! str_contains((string) $last->reason, $l->grn)) {
                continue;
            }
            $before = $price->amount_minor;
            $line = DB::table('goods_receipt_lines')->where('goods_receipt_id', $l->goods_receipt_id)->where('product_id', $l->product_id)->orderByDesc('line_no')->value('id');
            $price->forceFill(['amount_minor' => (int) $l->new_price_minor, 'set_by_hand_at' => $l->posted_at, 'set_by_hand_cost_line_id' => $line,
                'version' => $price->version + 1])->save();
            app(AuditLog::class)->record($actor, 'price.restored', $price, ['amount_minor' => [$before, (int) $l->new_price_minor]],
                "The selling price from {$l->number}, undone by the pricing rules from the same delivery ({$l->grn})");
            $restored++;
        }

        return $restored;
    }

    /** Waiting suggestions that would undo a price set on purpose after the cost they come from. */
    public function dismissSuggestionsOverHandPrices(string $tenantId): int
    {
        return DB::update(<<<'SQL'
            UPDATE price_suggestions s SET status = 'dismissed', decided_by_name = 'Price set by hand after this cost', decided_at = NOW()
            FROM prices p
            WHERE s.tenant_id = ? AND s.status = 'pending' AND p.price_list_id = s.price_list_id AND p.product_uom_id = s.product_uom_id
              AND p.min_qty = 1 AND p.starts_at IS NULL AND p.ends_at IS NULL AND p.set_by_hand_at IS NOT NULL
              AND p.set_by_hand_cost_line_id IS NOT DISTINCT FROM (SELECT gl.id FROM goods_receipt_lines gl JOIN goods_receipts g ON g.id = gl.goods_receipt_id
                  WHERE gl.product_id = s.product_id AND g.tenant_id = s.tenant_id AND g.status = 'posted' ORDER BY g.posted_at DESC, g.id DESC, gl.line_no DESC LIMIT 1)
            SQL, [$tenantId]);
    }
}
