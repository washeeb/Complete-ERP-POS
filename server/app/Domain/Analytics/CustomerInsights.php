<?php

namespace App\Domain\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Customers: RFM segments (how recently, how often, how much — till sales and invoices over a year), churn risk
 * (regulars overdue for their next visit), basket analysis (what sells together) and a next-best offer per customer.
 */
final class CustomerInsights
{
    public const SEGMENTS = ['champions' => 'Champions', 'loyal' => 'Loyal', 'promising' => 'New and promising', 'at_risk' => 'At risk',
        'hibernating' => 'Hibernating', 'attention' => 'Needs attention'];

    /** @return list<array{customer_id: string, code: string, name: string, last: string, days: int, orders: int, spend_minor: int, r: int, f: int, m: int, segment: string}> */
    public function rfm(string $tenantId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $since = $today->subDays(365);
        $till = DB::table('sales')->where('tenant_id', $tenantId)->where('status', 'posted')->whereNotNull('customer_id')->where('occurred_at', '>=', $since)
            ->selectRaw('customer_id, occurred_at::date AS d, net_minor AS v');
        $inv = DB::table('invoices')->where('tenant_id', $tenantId)->where('issued_on', '>=', $since->toDateString())
            ->selectRaw('customer_id, issued_on AS d, net_minor AS v');
        $rows = DB::query()->fromSub($till->unionAll($inv), 'x')->join('customers as c', 'c.id', '=', 'x.customer_id')
            ->groupBy('x.customer_id', 'c.code', 'c.name')
            ->selectRaw('x.customer_id, c.code, c.name, MAX(x.d)::text AS last, COUNT(*) FILTER (WHERE x.v > 0) AS orders, SUM(x.v) AS spend')
            ->havingRaw('SUM(x.v) > 0')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $out = $rows->map(fn ($r) => ['customer_id' => $r->customer_id, 'code' => $r->code, 'name' => $r->name, 'last' => $r->last,
            'days' => (int) CarbonImmutable::parse($r->last)->diffInDays($today), 'orders' => (int) $r->orders, 'spend_minor' => (int) $r->spend])->all();
        // Quintile 1–5 by how many customers have a lower value (ties share a score).
        $score = function (array $values) {
            $n = count($values);

            return function ($v) use ($values, $n) {
                $below = count(array_filter($values, fn ($s) => $s < $v));

                return $n > 1 ? min(5, (int) floor($below * 5 / $n) + 1) : 3;
            };
        };
        // Recency in fixed bands (meaningful even with few customers); frequency and spend by quintile.
        $r = fn (int $days) => match (true) {
            $days <= 30 => 5, $days <= 60 => 4, $days <= 90 => 3, $days <= 180 => 2, default => 1
        };
        $f = $score(array_column($out, 'orders'));
        $m = $score(array_column($out, 'spend_minor'));
        foreach ($out as &$c) {
            $c += ['r' => $r($c['days']), 'f' => $f($c['orders']), 'm' => $m($c['spend_minor'])];
            $c['segment'] = match (true) {
                $c['r'] >= 4 && $c['f'] >= 4 => 'champions',
                $c['f'] >= 4 => $c['r'] >= 3 ? 'loyal' : 'at_risk',
                $c['r'] >= 4 => 'promising',
                $c['r'] <= 2 && $c['f'] >= 3 => 'at_risk',
                $c['r'] <= 2 => 'hibernating',
                default => 'attention',
            };
        }
        unset($c);
        usort($out, fn ($a, $b) => $b['spend_minor'] <=> $a['spend_minor']);

        return $out;
    }

    /** @return array<string, array{segment: string, customers: int, spend_minor: int}> */
    public function segments(string $tenantId, ?CarbonImmutable $today = null): array
    {
        $out = [];
        foreach (self::SEGMENTS as $key => $label) {
            $out[$key] = ['segment' => $label, 'customers' => 0, 'spend_minor' => 0];
        }
        foreach ($this->rfm($tenantId, $today) as $c) {
            $out[$c['segment']]['customers']++;
            $out[$c['segment']]['spend_minor'] += $c['spend_minor'];
        }

        return $out;
    }

    /** Regular customers (3+ purchases) whose gap since the last one is more than twice their usual gap. */
    public function churnRisk(string $tenantId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $rows = DB::table('sales as s')->join('customers as c', 'c.id', '=', 's.customer_id')->where('s.tenant_id', $tenantId)->where('s.status', 'posted')
            ->where('s.kind', '<>', 'return')->where('s.occurred_at', '>=', $today->subDays(365))
            ->groupBy('s.customer_id', 'c.code', 'c.name')->havingRaw('COUNT(DISTINCT s.occurred_at::date) >= 3')
            ->selectRaw('s.customer_id, c.code, c.name, MIN(s.occurred_at)::date::text AS first, MAX(s.occurred_at)::date::text AS last, COUNT(DISTINCT s.occurred_at::date) AS visits, SUM(s.net_minor) AS spend')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $gap = CarbonImmutable::parse($r->first)->diffInDays(CarbonImmutable::parse($r->last)) / max(1, $r->visits - 1);
            $since = CarbonImmutable::parse($r->last)->diffInDays($today);
            if ($since > max(30, 2 * $gap)) {
                $out[] = ['customer_id' => $r->customer_id, 'code' => $r->code, 'name' => $r->name, 'last' => $r->last, 'usual_gap_days' => (int) round($gap),
                    'days_since' => (int) $since, 'visits' => (int) $r->visits, 'spend_minor' => (int) $r->spend];
            }
        }
        usort($out, fn ($a, $b) => $b['spend_minor'] <=> $a['spend_minor']);

        return $out;
    }

    /** Pairs of products bought together on the same receipt over the last 90 days, by lift. */
    public function baskets(string $tenantId, int $minTogether = 3, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();
        $base = fn () => DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->where('s.tenant_id', $tenantId)->where('s.status', 'posted')
            ->where('s.kind', '<>', 'return')->where('l.qty', '>', 0)->where('s.occurred_at', '>=', $today->subDays(90));
        $receipts = (int) $base()->distinct()->count('s.id');
        if ($receipts === 0) {
            return [];
        }
        $single = $base()->groupBy('l.product_id')->selectRaw('l.product_id, COUNT(DISTINCT s.id) AS n')->pluck('n', 'product_id');
        $pairs = DB::query()->fromSub($base()->select('s.id as sale_id', 'l.product_id')->distinct(), 'a')
            ->joinSub($base()->select('s.id as sale_id', 'l.product_id')->distinct(), 'b', fn ($j) => $j->on('a.sale_id', '=', 'b.sale_id')->whereColumn('a.product_id', '<', 'b.product_id'))
            ->groupBy('a.product_id', 'b.product_id')->havingRaw('COUNT(*) >= ?', [$minTogether])
            ->selectRaw('a.product_id AS a, b.product_id AS b, COUNT(*) AS n')->orderByDesc('n')->limit(200)->get();
        $names = DB::table('products')->whereIn('id', $pairs->pluck('a')->merge($pairs->pluck('b'))->unique())->pluck('name', 'id');
        $out = $pairs->map(fn ($p) => ['a_id' => $p->a, 'b_id' => $p->b, 'a' => $names[$p->a], 'b' => $names[$p->b], 'together' => (int) $p->n,
            'confidence_pct' => (int) round(100 * $p->n / max(1, min($single[$p->a], $single[$p->b]))),
            'lift' => round(($p->n * $receipts) / max(1, $single[$p->a] * $single[$p->b]), 2)])->sortByDesc('lift')->values()->all();

        return array_slice($out, 0, 30);
    }

    /** What to offer a customer next: the strongest partner of what they buy most that they have not bought. */
    public function nextBestOffer(string $tenantId, string $customerId): ?array
    {
        $theirs = DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->where('s.customer_id', $customerId)->where('s.status', 'posted')
            ->groupBy('l.product_id')->orderByRaw('SUM(l.qty) DESC')->pluck('l.product_id')->all();
        if (! $theirs) {
            return null;
        }
        foreach ($this->baskets($tenantId, 2) as $p) {
            foreach ([[$p['a_id'], $p['b_id'], $p['b']], [$p['b_id'], $p['a_id'], $p['a']]] as [$have, $offer, $name]) {
                if (in_array($have, $theirs, true) && ! in_array($offer, $theirs, true)) {
                    return ['product_id' => $offer, 'name' => $name, 'because' => $have === $p['a_id'] ? $p['a'] : $p['b'], 'lift' => $p['lift']];
                }
            }
        }

        return null;
    }
}
