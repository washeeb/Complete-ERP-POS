<?php

namespace App\Domain\Purchasing;

use App\Models\Supplier;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;

/**
 * How a supplier has served the company over the last months, from what happened (no one types a rating):
 *   - on time: orders whose first delivery came by the expected date;
 *   - fill rate: of what was ordered on finished orders, how much came;
 *   - quality: what went back as damaged, recalled or wrong, against what was received (expiry returns are not the
 *     supplier's fault and are left out);
 *   - price changes: bills above (or below) the order price, against what was ordered;
 *   - lead time: days from approving an order to its first delivery.
 * The score (0–100) weighs them 40 / 30 / 20 / 10; A from 85, B from 70, C from 50, else D. Null with no orders.
 */
final class SupplierScorecard
{
    /** @return array{orders: int, on_time: ?int, fill: ?int, quality_issues: ?float, price_change: ?float, lead_days: ?float, score: ?int, grade: ?string} */
    public static function for(Supplier $s, int $months = 12): array
    {
        $from = now()->subMonths($months);
        $pos = DB::table('purchase_orders')->where('supplier_id', $s->id)->whereNotNull('approved_at')->where('approved_at', '>=', $from)
            ->whereNotIn('status', ['cancelled'])->get(['id', 'status', 'expected_on', 'approved_at']);
        $out = ['orders' => $pos->count(), 'on_time' => null, 'fill' => null, 'quality_issues' => null, 'price_change' => null, 'lead_days' => null, 'score' => null, 'grade' => null];
        if ($pos->isEmpty()) {
            return $out;
        }
        $first = DB::table('goods_receipts')->whereIn('purchase_order_id', $pos->pluck('id'))->where('status', 'posted')
            ->groupBy('purchase_order_id')->selectRaw('purchase_order_id, MIN(posted_at) AS at')->pluck('at', 'purchase_order_id');

        $dated = $pos->filter(fn ($p) => $p->expected_on && isset($first[$p->id]));
        if ($dated->isNotEmpty()) {
            $onTime = $dated->filter(fn ($p) => substr((string) $first[$p->id], 0, 10) <= $p->expected_on)->count();
            $out['on_time'] = (int) round(100 * $onTime / $dated->count());
        }
        $leads = $pos->filter(fn ($p) => isset($first[$p->id]))->map(fn ($p) => (strtotime((string) $first[$p->id]) - strtotime((string) $p->approved_at)) / 86400);
        if ($leads->isNotEmpty()) {
            $out['lead_days'] = round(max(0, $leads->avg()), 1);
        }
        $finished = $pos->whereIn('status', ['received', 'closed'])->pluck('id');
        if ($finished->isNotEmpty()) {
            $q = DB::table('purchase_order_lines')->whereIn('purchase_order_id', $finished)->selectRaw('SUM(qty) AS ordered, SUM(received_qty) AS got')->first();
            if ((float) $q->ordered > 0) {
                $out['fill'] = (int) min(100, round(100 * Qty::toMilli((string) $q->got) / Qty::toMilli((string) $q->ordered)));
            }
        }
        $received = (int) DB::table('goods_receipts')->where('supplier_id', $s->id)->where('status', 'posted')->where('posted_at', '>=', $from)->sum('net_minor');
        if ($received > 0) {
            $bad = (int) DB::table('supplier_returns')->where('supplier_id', $s->id)->where('status', 'posted')->where('posted_at', '>=', $from)
                ->whereNotIn('reason', ['expired', 'near_expiry'])->sum('total_minor');
            $out['quality_issues'] = round(100 * $bad / $received, 1);
        }
        $bills = DB::table('supplier_bills')->where('supplier_id', $s->id)->where('status', 'posted')->where('bill_date', '>=', $from->toDateString())
            ->selectRaw('SUM(variance_minor) AS v, SUM(net_minor) AS n')->first();
        $ordered = (int) $bills->n - (int) $bills->v;
        if ($ordered > 0) {
            $out['price_change'] = round(100 * (int) $bills->v / $ordered, 1);
        }

        $parts = array_filter([
            [$out['on_time'], 40],
            [$out['fill'], 30],
            [$out['quality_issues'] === null ? null : max(0, 100 - $out['quality_issues'] * 10), 20], // 10 % sent back for faults = 0
            [$out['price_change'] === null ? null : max(0, 100 - abs($out['price_change']) * 10), 10], // 10 % off the order price = 0
        ], fn ($p) => $p[0] !== null);
        if ($parts) {
            $weight = array_sum(array_column($parts, 1));
            $out['score'] = (int) round(array_sum(array_map(fn ($p) => $p[0] * $p[1], $parts)) / $weight);
            $out['grade'] = $out['score'] >= 85 ? 'A' : ($out['score'] >= 70 ? 'B' : ($out['score'] >= 50 ? 'C' : 'D'));
        }

        return $out;
    }
}
