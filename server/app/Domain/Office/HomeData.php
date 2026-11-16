<?php

namespace App\Domain\Office;

use App\Domain\Purchasing\ReorderLevels;
use App\Filament\Pages\ExpiryReturns;
use App\Filament\Pages\ReorderSuggestions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Widgets\Panels\MyApprovals;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Look;
use App\Support\Money;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The new back office's Home (owner request 2026-11-16, after the NetSuite-style template: reminders, tiles, KPIs with
 * the period before, a KPI meter, top items, the monthly trend, shortcuts). Every figure is computed exactly as the
 * current dashboard computes it, and only what the person may see, for their branches.
 */
final class HomeData
{
    public const PERIODS = ['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'quarter' => 'This quarter', 'year' => 'This year'];

    /** Menu counts that are someone waiting for a decision (shown big); other counts (tickets, alerts, activity) are listed small. */
    public const WAITING_ON_SOMEONE = ['/admin/till-requests', '/admin/review-items', '/admin/access-requests'];

    private string $tenantId;

    private string $tz;

    public function __construct(private readonly User $user)
    {
        $this->tenantId = $user->tenant_id;
        $this->tz = Tenant::find($user->tenant_id)?->timezone ?: 'UTC';
    }

    /** @return array<string, mixed> */
    public function build(string $period, string $topPeriod = 'month'): array
    {
        [$from, $to, $prevFrom, $prevTo, $label, $prevLabel] = $this->range($period);
        $currency = Tenant::find($this->tenantId)?->currency_code ?? 'GHS';

        return [
            'currency' => $currency, 'exponent' => Money::exponent($currency),
            'period' => ['key' => $period, 'label' => $label, 'previous' => $prevLabel, 'options' => self::PERIODS],
            'reminders' => $this->reminders(),
            'kpis' => $this->kpis($from, $to, $prevFrom, $prevTo),
            'top_items' => $this->topItems($topPeriod),
            'trend' => $this->trend(),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable, 4: string, 5: string} */
    private function range(string $period): array
    {
        $now = CarbonImmutable::now($this->tz);
        $today = $now->startOfDay();

        return match ($period) {
            'today' => [$today, $now, $today->subDay(), $now->subDay(), 'Today', 'Yesterday at this time'],
            'week' => [$today->startOfWeek(), $now, $today->startOfWeek()->subWeek(), $now->subWeek(), 'This week', 'Last week to the same day'],
            'quarter' => [$today->firstOfQuarter(), $now, $today->firstOfQuarter()->subQuarter(), $now->subQuarter(), 'This quarter', 'Last quarter to the same day'],
            'year' => [$today->startOfYear(), $now, $today->startOfYear()->subYear(), $now->subYear(), 'This year', 'Last year to the same day'],
            default => [$today->startOfMonth(), $now, $today->startOfMonth()->subMonthNoOverflow(), $now->subMonthNoOverflow(), 'This month', 'Last month to the same day'],
        };
    }

    /** What waits: approvals for this person, pages with something waiting (the menu's own counts), stock and buying. */
    private function reminders(): array
    {
        $out = [];
        $add = function (string $label, int $count, ?string $url, bool $urgent = false) use (&$out) {
            if ($url !== null && ! collect($out)->contains('url', $url)) {
                $out[] = ['label' => $label, 'count' => $count, 'url' => $url, 'urgent' => $urgent];
            }
        };
        try {
            if (MyApprovals::canView()) {
                foreach ((new MyApprovals)->items() as $i) {
                    $add($i['label'].' to approve', $i['count'], $i['url'] ? $this->path($i['url']) : null, $i['count'] > 0);
                }
            }
        } catch (Throwable) {
            // a panel that cannot answer for this person is left out, never the whole Home
        }
        // Anything in the menu showing a count (till requests, the review queue, refused till work…).
        foreach (Look::tabs() as $tab) {
            foreach ($tab['sections'] as $section) {
                foreach ($section['items'] as $item) {
                    if (is_numeric($item['badge']) && (int) $item['badge'] > 0) {
                        $path = $this->path($item['url']);
                        $add($item['label'], (int) $item['badge'], $path, in_array(explode('?', $path)[0], self::WAITING_ON_SOMEONE, true));
                    }
                }
            }
        }
        if (Erp::canAnywhere('stock.view')) {
            $warehouses = $this->warehouses('stock.view');
            $today = CarbonImmutable::now($this->tz)->startOfDay();
            $batches = fn (int $days) => (int) DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
                ->whereIn('l.warehouse_id', $warehouses)->where('l.on_hand', '>', 0)->whereNotNull('b.expires_on')
                ->where('b.expires_on', '<=', $today->addDays($days)->toDateString())->distinct()->count('l.batch_id');
            $days = (int) app(Settings::class)->get('stock.expiry_alert_days', $this->tenantId);
            $expiry = ExpiryReturns::canAccess() ? $this->path(ExpiryReturns::getUrl()) : null;
            $low = 0;
            foreach (Warehouse::whereIn('id', $warehouses)->where('is_active', true)->get() as $w) {
                $low += count(app(ReorderLevels::class)->low($w));
            }
            $add('Products below their minimum', $low, ReorderSuggestions::canAccess() ? $this->path(ReorderSuggestions::getUrl()) : null, $low > 0);
            $add('Expired batches still in stock', $expired = $batches(-1), $expiry, $expired > 0);
            if ($expiry) {
                $out[] = ['label' => "Batches expiring in {$days} days", 'count' => $batches($days), 'url' => $expiry, 'urgent' => false];
            }
        }
        if (Erp::canAnywhere('purchasing.view')) {
            $branches = Erp::branchIds('purchasing.view');
            $open = DB::table('purchase_orders')->where('tenant_id', $this->tenantId)->when($branches !== null, fn ($q) => $q->whereIn('branch_id', $branches))
                ->whereIn('status', ['approved', 'partially_received']);
            $due = (clone $open)->whereNotNull('expected_on')->where('expected_on', '<=', CarbonImmutable::now($this->tz)->addDays(7)->toDateString())->count();
            $url = $this->path(PurchaseOrderResource::getUrl('index'));
            $out[] = ['label' => 'Deliveries due this week or late', 'count' => $due, 'url' => $url, 'urgent' => $due > 0];
            $out[] = ['label' => 'Purchase orders to receive', 'count' => (clone $open)->count(), 'url' => $url, 'urgent' => false];
        }

        // Urgent ones with something waiting first, then the rest with something, then the empty ones.
        usort($out, fn ($a, $b) => [$b['urgent'] && $b['count'] > 0, $b['count'] > 0] <=> [$a['urgent'] && $a['count'] > 0, $a['count'] > 0]);

        return $out;
    }

    /** Headline figures: this period against the same part of the period before, with a little trend line. */
    private function kpis(CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $pFrom, CarbonImmutable $pTo): array
    {
        $out = [];
        $buckets = $this->buckets($from, $to);
        if (Erp::canAnywhere('sales.view')) {
            $ids = Erp::branchIds('sales.view');
            $sales = fn (CarbonImmutable $a, CarbonImmutable $b) => DB::table('sales')->where('tenant_id', $this->tenantId)->where('status', 'posted')
                ->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->where('occurred_at', '>=', $a->utc())->where('occurred_at', '<', $b->utc());
            $now = $sales($from, $to);
            $before = $sales($pFrom, $pTo);
            $s = (int) (clone $now)->sum('total_minor');
            $n = (clone $now)->count();
            $ps = (int) (clone $before)->sum('total_minor');
            $pn = (clone $before)->count();
            $out[] = $this->kpi('sales', 'Sales', 'money', $s, $ps, array_map(fn ($b) => (int) $sales($b[0], $b[1])->sum('total_minor'), $buckets), '/admin/sales');
            $out[] = $this->kpi('receipts', 'Receipts (sales made)', 'count', $n, $pn, array_map(fn ($b) => $sales($b[0], $b[1])->count(), $buckets), '/admin/sales');
            $out[] = $this->kpi('basket', 'Average sale', 'money', $n ? intdiv($s, $n) : 0, $pn ? intdiv($ps, $pn) : 0, [], '/admin/sales');
        }
        if (Erp::canAnywhere('stock.view')) {
            $v = (int) DB::table('stock_levels')->whereIn('warehouse_id', $this->warehouses('stock.view'))
                ->selectRaw('COALESCE(SUM(ROUND(on_hand * COALESCE(avg_cost_minor, 0))), 0) AS v')->value('v');
            $out[] = $this->kpi('stock', 'Stock value (at cost)', 'money', $v, null, [], '/admin/stock-levels');
        }
        if (Erp::canAnywhere('purchasing.view')) {
            $b = Erp::branchIds('purchasing.view');
            $po = fn () => DB::table('purchase_orders')->where('tenant_id', $this->tenantId)->when($b !== null, fn ($q) => $q->whereIn('branch_id', $b));
            $ordered = fn (CarbonImmutable $x, CarbonImmutable $y) => (int) $po()->whereNotIn('status', ['draft', 'pending_approval', 'cancelled'])
                ->where('approved_at', '>=', $x->utc())->where('approved_at', '<', $y->utc())->sum('total_minor');
            $open = $po()->whereIn('status', ['approved', 'partially_received']);
            $out[] = $this->kpi('open_po', 'Open purchase orders', 'count', (clone $open)->count(), null, [], '/admin/purchase-orders');
            $out[] = $this->kpi('open_po_value', 'Value of open purchase orders', 'money', (int) (clone $open)->sum('total_minor'), null, [], '/admin/purchase-orders');
            $out[] = $this->kpi('purchases', 'Bought (orders approved)', 'money', $ordered($from, $to), $ordered($pFrom, $pTo),
                array_map(fn ($x) => $ordered($x[0], $x[1]), $buckets), '/admin/purchase-orders');
        }
        if (Erp::canAnywhere('receivables.view') && Erp::branchIds('receivables.view') === null) {
            $out[] = $this->kpi('owed_to_us', 'Customers owe', 'money', (int) DB::table('customer_balances')->where('tenant_id', $this->tenantId)
                ->where('balance_minor', '>', 0)->sum('balance_minor'), null, [], '/admin/customers');
        }
        if (Erp::canAnywhere('payables.view') && Erp::branchIds('payables.view') === null) {
            $out[] = $this->kpi('we_owe', 'We owe suppliers', 'money', (int) DB::table('supplier_ledger')->where('tenant_id', $this->tenantId)->sum('amount_minor'), null, [], '/admin/suppliers');
        }

        return $out;
    }

    private function kpi(string $key, string $label, string $format, int $current, ?int $previous, array $spark, string $url): array
    {
        $change = $previous === null ? null : ($previous === 0 ? ($current === 0 ? 0.0 : null) : round(($current - $previous) * 100 / abs($previous), 1));

        return ['key' => $key, 'label' => $label, 'format' => $format, 'current' => $current, 'previous' => $previous, 'change' => $change,
            'spark' => array_values($spark), 'url' => $url];
    }

    /** @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> up to 12 equal steps across the period, for the trend line */
    private function buckets(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $span = max(1, $to->getTimestamp() - $from->getTimestamp());
        $n = min(12, max(2, (int) ceil($span / 3600 / ($span > 86400 * 2 ? 24 : 2))));
        $step = intdiv($span, $n);

        return array_map(fn ($i) => [$from->addSeconds($i * $step), $i === $n - 1 ? $to : $from->addSeconds(($i + 1) * $step)], range(0, $n - 1));
    }

    /** The 15 items sold most, by quantity (base units), in the period chosen on the panel. */
    private function topItems(string $period): array
    {
        if (! Erp::canAnywhere('sales.view')) {
            return [];
        }
        [$from, $to] = $this->range($period);
        $ids = Erp::branchIds('sales.view');

        return DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')
            ->where('s.tenant_id', $this->tenantId)->where('s.status', 'posted')->whereNotNull('l.product_id')
            ->when($ids !== null, fn ($q) => $q->whereIn('s.branch_id', $ids))
            ->where('s.occurred_at', '>=', $from->utc())->where('s.occurred_at', '<', $to->utc())
            ->groupBy('l.product_id')->selectRaw('l.product_id, MAX(l.name) AS name, SUM(l.qty * l.factor) AS qty, SUM(l.line_total_minor) AS total')
            ->orderByDesc('qty')->limit(15)->get()
            ->map(fn ($r) => ['id' => $r->product_id, 'name' => $r->name, 'qty' => round((float) $r->qty, 3), 'total' => (int) $r->total])->all();
    }

    /** The last 12 months: sales and purchases (orders approved), each month in the company's time zone. */
    private function trend(): array
    {
        $months = collect(range(11, 0))->map(fn ($i) => CarbonImmutable::now($this->tz)->startOfMonth()->subMonthsNoOverflow($i));
        $series = [];
        if (Erp::canAnywhere('sales.view')) {
            $ids = Erp::branchIds('sales.view');
            $series[] = ['key' => 'sales', 'label' => 'Sales', 'values' => $months->map(fn ($m) => (int) DB::table('sales')->where('tenant_id', $this->tenantId)
                ->where('status', 'posted')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))
                ->where('occurred_at', '>=', $m->utc())->where('occurred_at', '<', $m->addMonthNoOverflow()->utc())->sum('total_minor'))->all()];
        }
        if (Erp::canAnywhere('purchasing.view')) {
            $b = Erp::branchIds('purchasing.view');
            $series[] = ['key' => 'purchases', 'label' => 'Bought', 'values' => $months->map(fn ($m) => (int) DB::table('purchase_orders')->where('tenant_id', $this->tenantId)
                ->when($b !== null, fn ($q) => $q->whereIn('branch_id', $b))->whereNotIn('status', ['draft', 'pending_approval', 'cancelled'])
                ->where('approved_at', '>=', $m->utc())->where('approved_at', '<', $m->addMonthNoOverflow()->utc())->sum('total_minor'))->all()];
        }

        return ['months' => $months->map(fn ($m) => $m->format('M y'))->all(), 'series' => $series];
    }

    /** @return list<string> */
    private function warehouses(string $permission): array
    {
        $b = Erp::branchIds($permission);

        return DB::table('warehouses')->where('tenant_id', $this->tenantId)->when($b !== null, fn ($q) => $q->whereIn('branch_id', $b))->pluck('id')->all();
    }

    private function path(string $url): string
    {
        $p = parse_url($url);

        return '/'.ltrim((string) ($p['path'] ?? ''), '/').(isset($p['query']) ? '?'.$p['query'] : '');
    }
}
