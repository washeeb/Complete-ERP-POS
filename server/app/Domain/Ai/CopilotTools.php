<?php

namespace App\Domain\Ai;

use App\Domain\Analytics\CashFlowForecaster;
use App\Domain\Analytics\CustomerInsights;
use App\Domain\Analytics\DemandForecaster;
use App\Domain\Analytics\PricingAdvisor;
use App\Domain\Analytics\StockHealth;
use App\Domain\Support\DomainException;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Money;
use App\Support\Qty;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the copilot may look at: fixed, parameterised, read-only report functions — never SQL written by the model.
 * Each tool needs a permission and sees only the branches the person may already see; company-wide money figures need
 * a company-wide grant. Names of customers and staff go through the masker (placeholders when a cloud model is used).
 *
 * A tool returns a table: ['title', 'columns', 'rows', 'note'?].
 */
final class CopilotTools
{
    /** name => [permission, company-wide only, personal data, description, JSON-schema properties] */
    private const TOOLS = [
        'sales_summary' => ['sales.view', false, false, 'Till sales totals (before tax, receipts) for a period, grouped by day, week, month, branch, category, product, cashier or payment method.',
            ['from' => ['type' => 'string', 'description' => 'First day, YYYY-MM-DD (default: start of this month)'], 'to' => ['type' => 'string', 'description' => 'Last day, YYYY-MM-DD (default: today)'],
                'group_by' => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'branch', 'category', 'product', 'cashier', 'payment_method']], 'branch' => ['type' => 'string', 'description' => 'Branch code (optional)']]],
        'stock_status' => ['stock.view', false, false, 'Stock on hand of products matching a name or SKU, per warehouse, with daily sales and days of cover.',
            ['search' => ['type' => 'string', 'description' => 'Product name or SKU'], 'branch' => ['type' => 'string']]],
        'reorder_suggestions' => ['stock.view', false, false, 'Products that will run out soonest at a branch, with forecast demand and a suggested order quantity.',
            ['branch' => ['type' => 'string', 'description' => 'Branch code (default: every branch you can see)']]],
        'demand_forecast' => ['stock.view', false, false, 'Sales forecast for products matching a name or SKU: next 7 and 28 days, trend, days of cover.',
            ['search' => ['type' => 'string'], 'branch' => ['type' => 'string']]],
        'stock_health' => ['stock.view', false, false, 'Dead stock, overstock, stock that may expire unsold, and suggested transfers between branches.',
            ['branch' => ['type' => 'string']]],
        'margins' => ['insights.view', true, false, 'Products whose price leaves less than the target margin over average cost (or sell below cost).', []],
        'customer_balances' => ['receivables.view', true, true, 'What customers owe on account, largest first, with credit limits.',
            ['search' => ['type' => 'string', 'description' => 'Customer name or code (optional)']]],
        'top_customers' => ['customers.view', true, true, 'Customers who bought the most in a period (till sales and invoices).',
            ['from' => ['type' => 'string'], 'to' => ['type' => 'string']]],
        'customer_segments' => ['insights.view', true, false, 'How many customers are champions, loyal, at risk, hibernating… and what they spent in the last year.', []],
        'supplier_payments_due' => ['payables.view', true, false, 'What is owed to suppliers, with the next due dates.', ['days' => ['type' => 'integer', 'description' => 'Due within this many days (default 14)']]],
        'cash_position' => ['finance.view', true, false, 'Money now in cash, bank, mobile money and settlements due, per account.', []],
        'cash_flow_forecast' => ['finance.view', true, false, 'Week-by-week cash forecast for the next weeks: till takings, customer payments, supplier bills, payroll, expenses.',
            ['weeks' => ['type' => 'integer', 'description' => '1 to 12 (default 8)']]],
        'open_alerts' => ['insights.view', false, false, 'Open alerts: running out, expiring, low margin, cash shortfall, loss prevention.', []],
    ];

    public function __construct(private readonly Authorizer $authz) {}

    /** Tool definitions this person may use (model-facing). @return list<array{name: string, description: string, parameters: array}> */
    public function definitions(User $user): array
    {
        $out = [];
        foreach (self::TOOLS as $name => [$perm, $companyWide, , $description, $props]) {
            if ($this->may($user, $perm, $companyWide)) {
                $out[] = ['name' => $name, 'description' => $description, 'parameters' => ['type' => 'object', 'properties' => (object) $props]];
            }
        }

        return $out;
    }

    public function isPersonal(string $name): bool
    {
        return self::TOOLS[$name][2] ?? false;
    }

    /** @return array{title: string, columns: list<string>, rows: list<list<string>>, note?: string} */
    public function run(User $user, Masker $masker, string $name, array $args): array
    {
        $def = self::TOOLS[$name] ?? throw new DomainException("There is no report called {$name}.");
        if (! $this->may($user, $def[0], $def[1])) {
            throw new DomainException('You do not have access to that information.');
        }
        $tenant = Tenant::findOrFail($user->tenant_id);
        $ctx = ['user' => $user, 'tenant' => $tenant, 'tz' => $tenant->timezone ?: 'UTC', 'masker' => $masker,
            'branches' => $this->branchScope($user, $def[0], $args['branch'] ?? null)];

        return $this->{lcfirst(str_replace('_', '', ucwords($name, '_')))}($ctx, $args);
    }

    private function may(User $user, string $perm, bool $companyWide): bool
    {
        $ids = $this->authz->branchIds($user, $perm);

        return $companyWide ? $ids === null : ($ids === null || $ids !== []);
    }

    /** @return list<string>|null branch ids to include (null = all) */
    private function branchScope(User $user, string $perm, ?string $code): ?array
    {
        $allowed = $this->authz->branchIds($user, $perm);
        if ($code === null || trim($code) === '') {
            return $allowed;
        }
        $id = DB::table('branches')->where('tenant_id', $user->tenant_id)->whereRaw('upper(code) = ?', [strtoupper(trim($code))])->value('id')
            ?? throw new DomainException("There is no branch {$code}.");
        if ($allowed !== null && ! in_array($id, $allowed, true)) {
            throw new DomainException("You do not have access to branch {$code}.");
        }

        return [$id];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(array $ctx, array $args): array
    {
        $today = CarbonImmutable::now($ctx['tz'])->startOfDay();
        try {
            $from = ! empty($args['from']) ? CarbonImmutable::parse($args['from'], $ctx['tz'])->startOfDay() : $today->startOfMonth();
            $to = ! empty($args['to']) ? CarbonImmutable::parse($args['to'], $ctx['tz'])->startOfDay() : $today;
        } catch (\Throwable) {
            throw new DomainException('Dates must look like 2026-10-31.');
        }
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->max($to->subDays(400)), $to];
    }

    private function money(array $ctx, int|float|null $minor): string
    {
        return Money::display((int) round((float) $minor), $ctx['tenant']->currency_code);
    }

    private function warehouses(array $ctx): array
    {
        return DB::table('warehouses')->where('tenant_id', $ctx['tenant']->id)->where('is_active', true)
            ->when($ctx['branches'] !== null, fn ($q) => $q->whereIn('branch_id', $ctx['branches']))->orderBy('code')->pluck('name', 'id')->all();
    }

    private function salesSummary(array $ctx, array $args): array
    {
        [$from, $to] = $this->period($ctx, $args);
        $group = $args['group_by'] ?? 'day';
        $tz = DB::getPdo()->quote($ctx['tz']);
        $day = "((s.occurred_at AT TIME ZONE 'UTC') AT TIME ZONE {$tz})::date";
        $q = DB::table('sales as s')->where('s.tenant_id', $ctx['tenant']->id)->where('s.status', 'posted')
            ->whereRaw("{$day} BETWEEN ? AND ?", [$from->toDateString(), $to->toDateString()])
            ->when($ctx['branches'] !== null, fn ($w) => $w->whereIn('s.branch_id', $ctx['branches']));
        [$label, $rows] = match ($group) {
            'day', 'week', 'month' => [ucfirst($group), $q->groupByRaw('1')->orderByRaw('1')
                ->selectRaw(($group === 'day' ? "{$day}::text" : "date_trunc('{$group}', {$day})::date::text").' AS k, SUM(s.net_minor) AS net, COUNT(*) FILTER (WHERE s.kind = \'sale\') AS n')->get()],
            'branch' => ['Branch', $q->join('branches as b', 'b.id', '=', 's.branch_id')->groupBy('b.code', 'b.name')->orderByRaw('SUM(s.net_minor) DESC')
                ->selectRaw("b.code || ' ' || b.name AS k, SUM(s.net_minor) AS net, COUNT(*) FILTER (WHERE s.kind = 'sale') AS n")->get()],
            'cashier' => ['Teller', $q->groupBy('s.cashier_name')->orderByRaw('SUM(s.net_minor) DESC')
                ->selectRaw("s.cashier_name AS k, SUM(s.net_minor) AS net, COUNT(*) FILTER (WHERE s.kind = 'sale') AS n")->get()],
            'payment_method' => ['Payment method', $q->join('sale_payments as p', 'p.sale_id', '=', 's.id')->groupBy('p.method')->orderByRaw('SUM(p.amount_minor) DESC')
                ->selectRaw('p.method AS k, SUM(p.amount_minor) AS net, COUNT(DISTINCT s.id) AS n')->get()],
            'product', 'category' => [ucfirst($group), $q->join('sale_lines as l', 'l.sale_id', '=', 's.id')->join('products as p', 'p.id', '=', 'l.product_id')
                ->when($group === 'category', fn ($w) => $w->leftJoin('categories as c', 'c.id', '=', 'p.category_id')->groupBy('c.name')->selectRaw("COALESCE(c.name, 'No category') AS k"))
                ->when($group === 'product', fn ($w) => $w->groupBy('p.sku', 'p.name')->selectRaw("p.sku || ' ' || p.name AS k"))
                ->selectRaw('SUM(l.net_minor) AS net, SUM(l.qty * l.factor) AS n')->orderByRaw('SUM(l.net_minor) DESC')->limit(30)->get()],
            default => throw new DomainException('Group by day, week, month, branch, category, product, cashier or payment_method.'),
        };
        $count = in_array($group, ['product', 'category'], true) ? 'Units' : 'Receipts';
        $total = $rows->sum('net');

        return ['title' => "Sales {$from->toDateString()} to {$to->toDateString()} by ".strtolower($label), 'columns' => [$label, $group === 'payment_method' ? 'Amount paid' : 'Sales before tax', $count, 'Share'],
            'rows' => $rows->map(fn ($r) => [$group === 'cashier' ? $ctx['masker']->token('Staff', $r->k) : (string) $r->k, $this->money($ctx, $r->net),
                rtrim(rtrim(number_format((float) $r->n, 3, '.', ''), '0'), '.'), $total ? round($r->net * 100 / $total, 1).' %' : ''])->all(),
            'note' => 'Total '.$this->money($ctx, $total).' (returns netted).'];
    }

    private function findProducts(array $ctx, ?string $search): array
    {
        $s = trim((string) $search);
        if ($s === '') {
            throw new DomainException('Say which product (name or SKU).');
        }

        return DB::table('products')->where('tenant_id', $ctx['tenant']->id)->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'ilike', "%{$s}%")->orWhere('sku', 'ilike', "%{$s}%"))->orderBy('name')->limit(10)->pluck('id')->all();
    }

    private function stockStatus(array $ctx, array $args): array
    {
        $ids = $this->findProducts($ctx, $args['search'] ?? null);
        $rows = [];
        foreach ($this->warehouses($ctx) as $wid => $wname) {
            foreach (app(DemandForecaster::class)->forWarehouse($wid, $ids, 28) as $f) {
                $rows[] = [$f['sku'], $f['name'], $wname, Qty::format((int) round($f['on_hand'] * 1000)), (string) $f['avg_daily'], $f['days_cover'] === null ? 'no recent sales' : (string) $f['days_cover']];
            }
        }

        return ['title' => 'Stock on hand', 'columns' => ['SKU', 'Product', 'Warehouse', 'On hand', 'Sold a day', 'Days of cover'], 'rows' => $rows];
    }

    private function reorderSuggestions(array $ctx, array $args): array
    {
        $rows = [];
        foreach ($this->warehouses($ctx) as $wid => $wname) {
            foreach (app(DemandForecaster::class)->forWarehouse($wid) as $f) {
                if ($f['suggested_order'] > 0 && $f['avg_daily'] > 0) {
                    $rows[] = [$f, $wname];
                }
            }
        }
        usort($rows, fn ($a, $b) => ($a[0]['days_cover'] ?? INF) <=> ($b[0]['days_cover'] ?? INF));

        return ['title' => 'Reorder suggestions', 'columns' => ['Warehouse', 'SKU', 'Product', 'On hand', 'On order', 'Days of cover', 'Next 28 days', 'Suggested order'],
            'rows' => array_map(fn ($r) => [$r[1], $r[0]['sku'], $r[0]['name'], (string) $r[0]['on_hand'], (string) $r[0]['on_order'], (string) ($r[0]['days_cover'] ?? '—'),
                (string) $r[0]['forecast_28'], (string) $r[0]['suggested_order']], array_slice($rows, 0, 30))];
    }

    private function demandForecast(array $ctx, array $args): array
    {
        $ids = $this->findProducts($ctx, $args['search'] ?? null);
        $rows = [];
        foreach ($this->warehouses($ctx) as $wid => $wname) {
            foreach (app(DemandForecaster::class)->forWarehouse($wid, $ids) as $f) {
                $rows[] = [$f['name'], $wname, (string) $f['avg_daily'], (string) $f['forecast_7'], (string) $f['forecast_28'],
                    $f['trend_pct'] === null ? '—' : sprintf('%+d %%', $f['trend_pct']), (string) ($f['days_cover'] ?? '—'), $f['stockout_on'] ?? '—'];
            }
        }

        return ['title' => 'Demand forecast', 'columns' => ['Product', 'Warehouse', 'Sold a day (recent)', 'Next 7 days', 'Next 28 days', 'Trend vs month before', 'Days of cover', 'Runs out about'],
            'rows' => $rows, 'note' => 'From the last 12 weeks: weekday pattern, paydays (25th–3rd) and public holidays.'];
    }

    private function stockHealth(array $ctx, array $args): array
    {
        $h = app(StockHealth::class)->report($ctx['tenant']->id, array_keys($this->warehouses($ctx)));
        $rows = [];
        foreach (array_slice($h['dead'], 0, 10) as $d) {
            $rows[] = ['Dead stock', $d['warehouse'], $d['name'], (string) $d['on_hand'], $this->money($ctx, $d['value_minor']), 'Last sold '.($d['last_sold'] ?? 'never')];
        }
        foreach (array_slice($h['overstock'], 0, 10) as $o) {
            $rows[] = ['Overstock', $o['warehouse'], $o['name'], (string) $o['on_hand'], $this->money($ctx, $o['excess_value_minor']), "{$o['days_cover']} days of stock"];
        }
        foreach (array_slice($h['expiry'], 0, 10) as $e) {
            $rows[] = ['May expire unsold', $e['warehouse'], $e['name'], (string) $e['at_risk'], $this->money($ctx, $e['value_minor']), "Expires {$e['expires_on']}; mark down {$e['markdown_pct']} %"];
        }
        foreach (array_slice($h['transfers'], 0, 10) as $t) {
            $rows[] = ['Transfer', "{$t['from']} → {$t['to']}", $t['name'], (string) $t['qty'], '', "{$t['to_days_cover']} days of cover where it is needed"];
        }

        return ['title' => 'Stock health', 'columns' => ['Issue', 'Where', 'Product', 'Quantity', 'Value at cost', 'Detail'], 'rows' => $rows];
    }

    private function margins(array $ctx, array $args): array
    {
        return ['title' => 'Low margins', 'columns' => ['SKU', 'Product', 'Price', 'Average cost', 'Margin', 'Price for target margin'],
            'rows' => array_map(fn ($m) => [$m['sku'], $m['name'], $this->money($ctx, $m['price_minor']), $this->money($ctx, $m['cost_minor']), "{$m['margin_pct']} %",
                $this->money($ctx, $m['price_for_target_minor'])], array_slice(app(PricingAdvisor::class)->margins($ctx['tenant']->id), 0, 30))];
    }

    private function customerBalances(array $ctx, array $args): array
    {
        $s = trim((string) ($args['search'] ?? ''));
        $rows = DB::table('customer_balances as b')->join('customers as c', 'c.id', '=', 'b.customer_id')->where('b.tenant_id', $ctx['tenant']->id)->where('b.balance_minor', '<>', 0)
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('c.name', 'ilike', "%{$s}%")->orWhere('c.code', 'ilike', "%{$s}%")))
            ->orderByDesc('b.balance_minor')->limit(30)->get(['c.code', 'c.name', 'b.balance_minor', 'c.credit_limit_minor']);

        return ['title' => 'Customer balances', 'columns' => ['Customer', 'Code', 'Owes', 'Credit limit'],
            'rows' => $rows->map(fn ($r) => [$ctx['masker']->token('Customer', $r->name), $r->code, $this->money($ctx, $r->balance_minor), $this->money($ctx, $r->credit_limit_minor)])->all()];
    }

    private function topCustomers(array $ctx, array $args): array
    {
        [$from, $to] = $this->period($ctx, $args);
        $till = DB::table('sales')->where('tenant_id', $ctx['tenant']->id)->where('status', 'posted')->whereNotNull('customer_id')
            ->where('occurred_at', '>=', $from->utc())->where('occurred_at', '<', $to->addDay()->utc())->selectRaw('customer_id, net_minor AS v');
        $inv = DB::table('invoices')->where('tenant_id', $ctx['tenant']->id)->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])->selectRaw('customer_id, net_minor AS v');
        $rows = DB::query()->fromSub($till->unionAll($inv), 'x')->join('customers as c', 'c.id', '=', 'x.customer_id')->groupBy('c.code', 'c.name')
            ->orderByRaw('SUM(x.v) DESC')->limit(20)->selectRaw('c.code, c.name, SUM(x.v) AS v, COUNT(*) AS n')->get();

        return ['title' => "Top customers {$from->toDateString()} to {$to->toDateString()}", 'columns' => ['Customer', 'Code', 'Bought (before tax)', 'Purchases'],
            'rows' => $rows->map(fn ($r) => [$ctx['masker']->token('Customer', $r->name), $r->code, $this->money($ctx, $r->v), (string) $r->n])->all()];
    }

    private function customerSegments(array $ctx, array $args): array
    {
        return ['title' => 'Customer segments (last 12 months)', 'columns' => ['Segment', 'Customers', 'Spent'],
            'rows' => array_values(array_map(fn ($s) => [$s['segment'], (string) $s['customers'], $this->money($ctx, $s['spend_minor'])], app(CustomerInsights::class)->segments($ctx['tenant']->id)))];
    }

    private function supplierPaymentsDue(array $ctx, array $args): array
    {
        $days = max(1, min(120, (int) ($args['days'] ?? 14)));
        $rows = DB::table('supplier_ledger as l')->join('suppliers as s', 's.id', '=', 'l.supplier_id')->where('l.tenant_id', $ctx['tenant']->id)
            ->groupBy('s.code', 's.name')->havingRaw('SUM(l.amount_minor) > 0')
            ->selectRaw('s.code, s.name, SUM(l.amount_minor) AS owed, MIN(l.due_on) FILTER (WHERE l.amount_minor > 0 AND l.due_on IS NOT NULL) AS due')->orderByRaw('2')->get()
            ->filter(fn ($r) => $r->due === null || $r->due <= now()->addDays($days)->toDateString())->sortBy('due');

        return ['title' => "Owed to suppliers (due within {$days} days or overdue)", 'columns' => ['Supplier', 'Code', 'Owed', 'Earliest due'],
            'rows' => $rows->map(fn ($r) => [$r->name, $r->code, $this->money($ctx, $r->owed), (string) ($r->due ?? '—')])->values()->all()];
    }

    private function cashPosition(array $ctx, array $args): array
    {
        $bankParent = DB::table('accounts')->where('tenant_id', $ctx['tenant']->id)->where('system_key', 'bank')->value('parent_id');
        $rows = DB::table('accounts as a')->leftJoin('journal_lines as j', 'j.account_id', '=', 'a.id')->where('a.tenant_id', $ctx['tenant']->id)->where('a.is_header', false)
            ->where(fn ($q) => $q->whereIn('a.system_key', CashFlowForecaster::MONEY)->when($bankParent, fn ($w) => $w->orWhere('a.parent_id', $bankParent)))
            ->groupBy('a.code', 'a.name')->orderBy('a.code')->selectRaw('a.code, a.name, COALESCE(SUM(j.amount_minor), 0) AS bal')->get();

        return ['title' => 'Money now', 'columns' => ['Account', 'Balance'], 'rows' => $rows->map(fn ($r) => ["{$r->code} {$r->name}", $this->money($ctx, $r->bal)])->all(),
            'note' => 'Total '.$this->money($ctx, $rows->sum('bal')).' (as posted to the books; recent till sales post within minutes).'];
    }

    private function cashFlowForecast(array $ctx, array $args): array
    {
        $f = app(CashFlowForecaster::class)->forecast($ctx['tenant']->id, max(1, min(12, (int) ($args['weeks'] ?? 8))));

        return ['title' => 'Cash-flow forecast', 'columns' => ['Week of', 'Start', 'Till takings', 'From customers', 'To suppliers', 'Payroll', 'Expenses', 'End'],
            'rows' => array_map(fn ($w) => [$w['week'], $this->money($ctx, $w['opening_minor']), $this->money($ctx, $w['till_minor']), $this->money($ctx, $w['customers_minor']),
                $this->money($ctx, $w['suppliers_minor']), $this->money($ctx, $w['payroll_minor']), $this->money($ctx, $w['expenses_minor']), $this->money($ctx, $w['closing_minor'])], $f['weeks']),
            'note' => 'Lowest: '.$this->money($ctx, $f['lowest_minor']).($f['lowest_week'] ? " in the week of {$f['lowest_week']}" : '').'. A planning guide, not a promise.'];
    }

    private function openAlerts(array $ctx, array $args): array
    {
        $loss = $this->authz->allowsAnywhere($ctx['user'], 'insights.loss_prevention');
        $rows = DB::table('ai_alerts')->where('tenant_id', $ctx['tenant']->id)->where('status', 'open')->when(! $loss, fn ($q) => $q->where('kind', '<>', 'loss'))
            ->when($ctx['branches'] !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $ctx['branches'])))
            ->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")->limit(30)->get(['kind', 'severity', 'title', 'detail', 'data']);

        return ['title' => 'Open alerts', 'columns' => ['Severity', 'Alert', 'Detail'],
            'rows' => $rows->map(fn ($r) => [$r->severity, $r->kind === 'loss' // the cashier's name goes through the masker
                ? 'Unusual till pattern: '.$ctx['masker']->token('Staff', json_decode($r->data, true)['name'] ?? '?') : $r->title, (string) $r->detail])->all()];
    }
}
