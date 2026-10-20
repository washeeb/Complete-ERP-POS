<?php

namespace App\Domain\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loss prevention: per cashier, how often money goes back out (refunds, refunds without a receipt), how much is given
 * away (discounts), how far the drawer is off at close (over / short), kitchen voids and the drawer opened without a
 * sale — each compared with the other
 * cashiers over the same period. A risk score (0–100) adds up how unusual someone is; it is a reason to look, not proof.
 */
final class LossPrevention
{
    public const MIN_RECEIPTS = 20; // below this a cashier is listed but not scored

    /**
     * @param  list<string>|null  $branchIds  null = all branches
     * @return list<array<string, mixed>> per cashier, highest risk first
     */
    public function cashiers(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, ?array $branchIds = null): array
    {
        $range = fn ($q, string $col) => $q->where($col, '>=', $from->utc())->where($col, '<', $to->utc())
            ->when($branchIds !== null, fn ($w) => $w->whereIn('branch_id', $branchIds));
        $sales = $range(DB::table('sales')->where('tenant_id', $tenantId)->where('status', 'posted'), 'occurred_at')
            ->groupBy('cashier_id', 'cashier_name')
            ->selectRaw("cashier_id, cashier_name,
                COUNT(*) FILTER (WHERE kind IS DISTINCT FROM 'return') AS receipts,
                COALESCE(SUM(total_minor) FILTER (WHERE kind IS DISTINCT FROM 'return'), 0) AS takings,
                COUNT(*) FILTER (WHERE kind = 'return') AS refunds,
                COALESCE(-SUM(total_minor) FILTER (WHERE kind = 'return'), 0) AS refund_value,
                COUNT(*) FILTER (WHERE kind = 'return' AND original_sale_id IS NULL) AS no_receipt_refunds,
                COALESCE(SUM(discount_minor) FILTER (WHERE kind IS DISTINCT FROM 'return'), 0) AS discounts")
            ->get()->keyBy('cashier_id');
        $shifts = $range(DB::table('shifts')->where('tenant_id', $tenantId)->where('status', 'closed'), 'closed_at')
            ->groupBy('closed_by')->selectRaw('closed_by, COUNT(*) AS shifts, COALESCE(SUM(ABS(over_short_minor)), 0) AS off, COUNT(*) FILTER (WHERE over_short_minor < 0) AS short')
            ->get()->keyBy('closed_by');
        $voids = DB::table('dining_order_items as i')->join('dining_orders as o', 'o.id', '=', 'i.dining_order_id')
            ->where('o.tenant_id', $tenantId)->where('i.status', 'void')->where('i.added_at', '>=', $from->utc())->where('i.added_at', '<', $to->utc())
            ->when($branchIds !== null, fn ($w) => $w->whereIn('o.branch_id', $branchIds))
            ->groupBy('i.voided_by_name')->selectRaw('i.voided_by_name AS name, COUNT(*) AS n')->pluck('n', 'name');
        $noSales = $range(DB::table('till_drawer_events')->where('tenant_id', $tenantId)->where('kind', 'no_sale'), 'occurred_at')
            ->groupBy('user_id')->selectRaw('user_id, COUNT(*) AS n')->pluck('n', 'user_id');

        $rows = [];
        foreach ($sales as $id => $s) {
            $receipts = (int) $s->receipts;
            $sh = $shifts[$id] ?? null;
            $rows[] = ['user_id' => $id, 'name' => $s->cashier_name, 'receipts' => $receipts, 'takings_minor' => (int) $s->takings,
                'refunds' => (int) $s->refunds, 'refund_value_minor' => (int) $s->refund_value, 'no_receipt_refunds' => (int) $s->no_receipt_refunds,
                'discount_minor' => (int) $s->discounts, 'shifts' => (int) ($sh->shifts ?? 0), 'short_shifts' => (int) ($sh->short ?? 0),
                'over_short_minor' => (int) ($sh->off ?? 0), 'voids' => (int) ($voids[$s->cashier_name] ?? 0), 'no_sales' => (int) ($noSales[$id] ?? 0),
                // Rates that make cashiers with different volumes comparable.
                'm' => ['refund_rate' => $receipts ? $s->refunds / $receipts : 0, 'refund_share' => $s->takings > 0 ? $s->refund_value / $s->takings : 0,
                    'no_receipt_rate' => $receipts ? $s->no_receipt_refunds / $receipts : 0, 'discount_share' => $s->takings > 0 ? $s->discounts / ($s->takings + $s->discounts) : 0,
                    'off_per_shift' => $sh && $sh->shifts ? $sh->off / $sh->shifts : 0, 'void_rate' => $receipts ? ($voids[$s->cashier_name] ?? 0) / $receipts : 0,
                    'no_sale_rate' => $receipts ? ($noSales[$id] ?? 0) / $receipts : 0]];
        }

        $scored = array_values(array_filter($rows, fn ($r) => $r['receipts'] >= self::MIN_RECEIPTS));
        $labels = ['refund_rate' => 'many refunds', 'refund_share' => 'high refund value', 'no_receipt_rate' => 'refunds without a receipt',
            'discount_share' => 'high discounts', 'off_per_shift' => 'drawer often off at close', 'void_rate' => 'many kitchen voids',
            'no_sale_rate' => 'drawer often opened without a sale'];
        // How many of each event lie behind a rate: a pattern needs at least 3 (one refund is not a pattern).
        $events = ['refund_rate' => 'refunds', 'refund_share' => 'refunds', 'no_receipt_rate' => 'no_receipt_refunds', 'discount_share' => null,
            'off_per_shift' => 'short_shifts', 'void_rate' => 'voids', 'no_sale_rate' => 'no_sales'];
        foreach ($rows as &$r) {
            $r['risk'] = null;
            $r['flags'] = [];
            if ($r['receipts'] < self::MIN_RECEIPTS) {
                unset($r['m']);

                continue;
            }
            $points = 0.0;
            $others = array_values(array_filter($scored, fn ($o) => $o['user_id'] !== $r['user_id']));
            foreach ($labels as $k => $label) {
                // Compared with the other cashiers (not including this one, so one person cannot hide their own pattern).
                $vals = array_column(array_column($others, 'm'), $k);
                $n = count($vals);
                $mean = $n ? array_sum($vals) / $n : 0.0;
                $sd = $n > 1 ? sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $vals)) / ($n - 1)) : 0.0;
                $v = $r['m'][$k];
                $z = ($v - $mean) / max($sd, $mean * 0.5, $v * 0.25, 1e-9); // floors keep a tiny spread from exaggerating
                $enough = $events[$k] === null || $r[$events[$k]] >= 3;
                if ($n >= 2 && $enough && $v > 0 && $v >= 2 * $mean && $z >= 2) {
                    $r['flags'][] = $label;
                    $points += min($z, 4);
                }
            }
            $r['risk'] = (int) min(100, round($points * 12.5));
            unset($r['m']);
        }
        unset($r);
        usort($rows, fn ($a, $b) => [$b['risk'] ?? -1, $b['receipts']] <=> [$a['risk'] ?? -1, $a['receipts']]);

        return $rows;
    }
}
