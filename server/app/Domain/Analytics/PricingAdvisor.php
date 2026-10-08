<?php

namespace App\Domain\Analytics;

use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Pricing advice: products whose price leaves too little margin over average cost, markdown suggestions for slow movers
 * (expiry markdowns are in stock health), and how much each recent promotion lifted sales.
 */
final class PricingAdvisor
{
    public function __construct(private readonly Settings $settings) {}

    /** Default price list, base unit, price before tax vs average cost across warehouses. */
    public function margins(string $tenantId): array
    {
        $alert = (int) $this->settings->get('insights.margin_alert_pct', $tenantId);
        $list = DB::table('price_lists')->where('tenant_id', $tenantId)->where('is_default', true)->first(['id', 'prices_include_tax']);
        if (! $list) {
            return [];
        }
        $rates = DB::table('tax_group_rates as gr')->join('tax_rates as r', 'r.id', '=', 'gr.tax_rate_id')->where('r.is_active', true)
            ->groupBy('gr.tax_group_id')->selectRaw('gr.tax_group_id, SUM(r.rate_bp) AS bp')->pluck('bp', 'tax_group_id');
        $rows = DB::table('prices as pr')->join('product_uoms as u', fn ($j) => $j->on('u.id', '=', 'pr.product_uom_id')->where('u.is_base', true))
            ->join('products as p', 'p.id', '=', 'pr.product_id')->where('pr.price_list_id', $list->id)->where('p.is_active', true)->where('p.track_stock', true)
            ->where(fn ($q) => $q->whereNull('pr.starts_at')->orWhere('pr.starts_at', '<=', now()))->where(fn ($q) => $q->whereNull('pr.ends_at')->orWhere('pr.ends_at', '>', now()))
            ->where('pr.min_qty', '<=', 1)
            ->joinSub(DB::table('stock_levels')->whereNotNull('avg_cost_minor')->groupBy('product_id')
                ->selectRaw('product_id, SUM(avg_cost_minor * GREATEST(on_hand, 0)) / NULLIF(SUM(GREATEST(on_hand, 0)), 0) AS cost, AVG(avg_cost_minor) AS any_cost'), 'c', 'c.product_id', '=', 'p.id')
            ->orderBy('pr.min_qty', 'desc')
            ->get(['p.id', 'p.sku', 'p.name', 'p.tax_group_id', 'pr.amount_minor', 'c.cost', 'c.any_cost'])->unique('id');
        $out = [];
        foreach ($rows as $r) {
            $cost = (float) ($r->cost ?? $r->any_cost);
            $bp = (int) ($rates[$r->tax_group_id] ?? 0);
            $net = $list->prices_include_tax ? $r->amount_minor * 10000 / (10000 + $bp) : (float) $r->amount_minor;
            if ($cost <= 0 || $net <= 0) {
                continue;
            }
            $margin = ($net - $cost) / $net * 100;
            if ($margin < $alert) {
                $out[] = ['product_id' => $r->id, 'sku' => $r->sku, 'name' => $r->name, 'price_minor' => (int) $r->amount_minor, 'net_minor' => (int) round($net),
                    'cost_minor' => (int) round($cost), 'margin_pct' => round($margin, 1), 'below_cost' => $net < $cost,
                    'price_for_target_minor' => (int) ceil(($cost / (1 - max($alert, 1) / 100)) * ($list->prices_include_tax ? (10000 + $bp) / 10000 : 1))];
            }
        }
        usort($out, fn ($a, $b) => $a['margin_pct'] <=> $b['margin_pct']);

        return $out;
    }

    /** Slow movers to mark down: over 90 days of stock at the current rate (or no sale in 60 days). */
    public function markdowns(string $tenantId, array $warehouseIds, ?CarbonImmutable $today = null): array
    {
        $health = app(StockHealth::class)->report($tenantId, $warehouseIds, $today);
        $out = [];
        foreach ($health['overstock'] as $o) {
            $out[] = $o + ['reason' => "{$o['days_cover']} days of stock", 'markdown_pct' => $o['days_cover'] > 365 ? 30 : ($o['days_cover'] > 180 ? 20 : 10)];
        }
        foreach ($health['dead'] as $d) {
            $out[] = $d + ['reason' => $d['last_sold'] ? "not sold since {$d['last_sold']}" : 'never sold here', 'markdown_pct' => 30];
        }

        return $out;
    }

    /** For promotions of the last 180 days on named products: daily units during the promotion vs the 28 days before. */
    public function promotionUplift(string $tenantId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $promos = DB::table('promotions')->where('tenant_id', $tenantId)->whereNotNull('starts_at')->where('starts_at', '>=', $today->subDays(180))
            ->where('starts_at', '<', $today)->get(['id', 'name', 'product_ids', 'starts_at', 'ends_at']);
        $out = [];
        foreach ($promos as $p) {
            $ids = json_decode($p->product_ids ?? '[]', true) ?: [];
            if (! $ids) {
                continue;
            }
            $start = CarbonImmutable::parse($p->starts_at);
            $end = $p->ends_at ? min(CarbonImmutable::parse($p->ends_at), $today) : $today;
            $days = max(1, $start->diffInDays($end));
            $units = fn (CarbonImmutable $a, CarbonImmutable $b) => (float) DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
                ->where('s.tenant_id', $tenantId)->where('s.status', 'posted')->whereIn('l.product_id', $ids)->where('s.occurred_at', '>=', $a)->where('s.occurred_at', '<', $b)
                ->sum(DB::raw('l.qty * l.factor'));
            $during = $units($start, $end) / $days;
            $before = $units($start->subDays(28), $start) / 28;
            $discount = (int) DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->where('s.tenant_id', $tenantId)->where('s.status', 'posted')
                ->whereIn('l.product_id', $ids)->where('s.occurred_at', '>=', $start)->where('s.occurred_at', '<', $end)->sum('l.discount_minor');
            $out[] = ['promotion' => $p->name, 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'before_daily' => round($before, 1), 'during_daily' => round($during, 1),
                'uplift_pct' => $before > 0 ? (int) round(($during / $before - 1) * 100) : null, 'extra_units' => round(($during - $before) * $days, 1), 'discount_minor' => $discount];
        }

        return $out;
    }
}
