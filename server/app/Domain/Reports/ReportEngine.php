<?php

namespace App\Domain\Reports;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Models\Tenant;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The report builder: fixed datasets (never SQL typed by a person), each with the columns a report may group by
 * ("dimensions") and the figures it may add up ("measures"). A report picks dimensions, measures, a period, a branch and
 * optional filters; without measures it lists the rows themselves (with a link to the source document). Every dataset
 * needs its permission, and branch-scoped people see their branches only.
 *
 * Definition: {dimensions: [..], measures: [..], period: today|yesterday|this_week|last_week|this_month|last_month|
 * last_7|last_30|this_year|last_year|custom, from, to, branch_id, filters: {dimension: value}, sort: dimension|measure,
 * direction: asc|desc, limit}.
 */
final class ReportEngine
{
    public const PERIODS = ['today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'last_week' => 'Last week', 'this_month' => 'This month',
        'last_month' => 'Last month', 'last_7' => 'Last 7 days', 'last_30' => 'Last 30 days', 'this_year' => 'This year', 'last_year' => 'Last year', 'custom' => 'Custom dates'];

    public const MAX_ROWS = 5000;

    public function __construct(private readonly Authorizer $authz) {}

    /** dataset => [label, permission, company-wide only, date expression|null, link pattern|null] */
    public static function datasets(): array
    {
        return [
            'sales_lines' => ['Till sales — items', 'sales.view', false, 's.occurred_at', '/admin/sales/{id}'],
            'receipts' => ['Till sales — receipts', 'sales.view', false, 's.occurred_at', '/admin/sales/{id}'],
            'payments' => ['Till payments', 'sales.view', false, 's.occurred_at', '/admin/sales/{id}'],
            'stock_levels' => ['Stock on hand', 'stock.view', false, null, null],
            'stock_movements' => ['Stock movements', 'stock.view', false, 'm.occurred_at', null],
            'invoices' => ['Customer invoices', 'receivables.view', false, 'i.issued_on', '/admin/invoices/{id}'],
            'supplier_bills' => ['Supplier bills', 'payables.view', false, 'b.bill_date', '/admin/supplier-bills/{id}'],
            'journal_lines' => ['General ledger lines', 'finance.view', true, 'e.entry_date', '/admin/journal-entries/{id}'],
        ];
    }

    /** dimension key => [label, SQL] and measure key => [label, SQL aggregate, money?] per dataset. */
    public static function fields(string $dataset): array
    {
        $date = fn (string $col) => ['date' => ['Day', "{$col}"], 'week' => ['Week', "date_trunc('week', {$col})::date"], 'month' => ['Month', "to_char({$col}, 'YYYY-MM')"]];

        return match ($dataset) {
            'sales_lines' => ['dims' => $date('LOCAL') + ['branch' => ['Branch', 'br.code'], 'cashier' => ['Cashier', 's.cashier_name'], 'category' => ['Category', "COALESCE(c.name, '—')"],
                'brand' => ['Brand', "COALESCE(bd.name, '—')"], 'product' => ['Product', "p.sku || ' ' || p.name"], 'customer' => ['Customer', "COALESCE(cu.name, 'Walk-in')"], 'kind' => ['Sale or return', 's.kind']],
                'measures' => ['qty' => ['Units', 'SUM(l.qty * l.factor)', false], 'net' => ['Sales before tax', 'SUM(l.net_minor)', true], 'tax' => ['Tax', 'SUM(l.tax_minor)', true],
                    'total' => ['Sales with tax', 'SUM(l.line_total_minor)', true], 'discount' => ['Discounts', 'SUM(l.discount_minor)', true], 'receipts' => ['Receipts', 'COUNT(DISTINCT s.id)', false]],
                'detail' => ['s.id', 'receipt' => ['Receipt', 's.receipt_no']]],
            'receipts' => ['dims' => $date('LOCAL') + ['branch' => ['Branch', 'br.code'], 'cashier' => ['Cashier', 's.cashier_name'], 'customer' => ['Customer', "COALESCE(cu.name, 'Walk-in')"],
                'kind' => ['Sale or return', 's.kind'], 'hour' => ['Hour of day', "to_char(LOCAL_TS, 'HH24')"]],
                'measures' => ['count' => ['Receipts', 'COUNT(*)', false], 'net' => ['Sales before tax', 'SUM(s.net_minor)', true], 'tax' => ['Tax', 'SUM(s.tax_minor)', true],
                    'total' => ['Sales with tax', 'SUM(s.total_minor)', true], 'discount' => ['Discounts', 'SUM(s.discount_minor)', true], 'average' => ['Average receipt', 'AVG(s.total_minor)', true],
                    'tips' => ['Tips', 'SUM(s.tip_minor)', true]],
                'detail' => ['s.id', 'receipt' => ['Receipt', 's.receipt_no']]],
            'payments' => ['dims' => $date('LOCAL') + ['branch' => ['Branch', 'br.code'], 'method' => ['Method', 'p.method'], 'cashier' => ['Cashier', 's.cashier_name']],
                'measures' => ['amount' => ['Amount', 'SUM(p.amount_minor)', true], 'count' => ['Payments', 'COUNT(*)', false]],
                'detail' => ['s.id', 'receipt' => ['Receipt', 's.receipt_no']]],
            'stock_levels' => ['dims' => ['warehouse' => ['Warehouse', 'w.code'], 'branch' => ['Branch', 'br.code'], 'category' => ['Category', "COALESCE(c.name, '—')"],
                'brand' => ['Brand', "COALESCE(bd.name, '—')"], 'product' => ['Product', "p.sku || ' ' || p.name"]],
                'measures' => ['on_hand' => ['On hand', 'SUM(sl.on_hand)', false], 'value' => ['Value at cost', 'SUM(sl.on_hand * COALESCE(sl.avg_cost_minor, 0))', true],
                    'products' => ['Products', 'COUNT(DISTINCT sl.product_id)', false]],
                'detail' => ['sl.id']],
            'stock_movements' => ['dims' => $date('LOCAL') + ['warehouse' => ['Warehouse', 'w.code'], 'branch' => ['Branch', 'br.code'], 'type' => ['Movement', 'm.type'],
                'product' => ['Product', "p.sku || ' ' || p.name"], 'reason' => ['Reason', "COALESCE(m.reason, '')"], 'by' => ['By', 'm.actor_name']],
                'measures' => ['qty' => ['Quantity (base units)', 'SUM(m.qty_base)', false], 'value' => ['Value at cost', 'SUM(ROUND(m.qty_entered * COALESCE(m.unit_cost_minor, 0)))', true],
                    'count' => ['Movements', 'COUNT(*)', false]],
                'detail' => ['m.id']],
            'invoices' => ['dims' => ['date' => ['Day', 'i.issued_on'], 'month' => ['Month', "to_char(i.issued_on, 'YYYY-MM')"], 'branch' => ['Branch', 'br.code'],
                'customer' => ['Customer', 'i.customer_name'], 'status' => ['Status', 'i.status'], 'number' => ['Invoice', 'i.number']],
                'measures' => ['count' => ['Invoices', 'COUNT(*)', false], 'net' => ['Before tax', 'SUM(i.net_minor)', true], 'tax' => ['Tax', 'SUM(i.tax_minor)', true], 'total' => ['Total', 'SUM(i.total_minor)', true]],
                'detail' => ['i.id']],
            'supplier_bills' => ['dims' => ['date' => ['Day', 'b.bill_date'], 'month' => ['Month', "to_char(b.bill_date, 'YYYY-MM')"], 'branch' => ['Branch', 'br.code'],
                'supplier' => ['Supplier', 'su.name'], 'status' => ['Status', 'b.status'], 'number' => ['Bill', 'b.number']],
                'measures' => ['count' => ['Bills', 'COUNT(*)', false], 'net' => ['Before tax', 'SUM(b.net_minor)', true], 'tax' => ['Tax', 'SUM(b.tax_minor)', true], 'total' => ['Total', 'SUM(b.total_minor)', true]],
                'detail' => ['b.id']],
            'journal_lines' => ['dims' => ['date' => ['Day', 'e.entry_date'], 'month' => ['Month', "to_char(e.entry_date, 'YYYY-MM')"], 'account' => ['Account', "a.code || ' ' || a.name"],
                'type' => ['Account type', 'a.type'], 'branch' => ['Branch', "COALESCE(br.code, '—')"], 'entry' => ['Entry', 'e.number']],
                'measures' => ['debit' => ['Debit', 'SUM(GREATEST(jl.amount_minor, 0))', true], 'credit' => ['Credit', 'SUM(GREATEST(-jl.amount_minor, 0))', true],
                    'balance' => ['Balance (debit − credit)', 'SUM(jl.amount_minor)', true], 'count' => ['Lines', 'COUNT(*)', false]],
                'detail' => ['e.id']],
            default => throw new DomainException('Unknown report data.'),
        };
    }

    /**
     * @param  array<string, string>  $drill  dimension => value: only the rows behind one line of a grouped report
     * @return array{title: string, headings: list<string>, rows: list<list<string>>, links: list<?string>, raw: list<array>, period: string, truncated: bool}
     */
    public function run(User $user, string $dataset, array $def, array $drill = []): array
    {
        [$label, $permission, $companyWide, $dateCol, $link] = self::datasets()[$dataset] ?? throw new DomainException('Unknown report data.');
        $branches = $this->authz->branchIds($user, $permission);
        if (! $this->authz->allowsAnywhere($user, $permission) || ($companyWide && $branches !== null)) {
            throw new AccessDenied;
        }
        $tenant = Tenant::findOrFail($user->tenant_id);
        $tz = $tenant->timezone ?: 'UTC';
        $quotedTz = DB::getPdo()->quote(preg_replace('/[^A-Za-z_\/+-]/', '', $tz));
        $local = $dateCol && str_ends_with($dateCol, 'occurred_at') ? "(({$dateCol} AT TIME ZONE 'UTC') AT TIME ZONE {$quotedTz})" : $dateCol;
        $fields = self::fields($dataset);
        $sub = fn (string $sql) => str_replace(['LOCAL_TS', 'LOCAL'], [$local ?? 'NULL', $local ? "{$local}::date" : 'NULL'], $sql);

        $dims = array_values(array_intersect(array_keys($fields['dims']), (array) ($def['dimensions'] ?? [])));
        $measures = array_values(array_intersect(array_keys($fields['measures']), (array) ($def['measures'] ?? [])));
        if (! $dims && ! $measures) {
            throw new DomainException('Choose at least one column or figure.');
        }
        [$from, $to] = self::range((string) ($def['period'] ?? 'this_month'), $def['from'] ?? null, $def['to'] ?? null, $tz);

        $q = $this->base($dataset, $tenant->id);
        $branchCol = ['stock_levels' => 'w.branch_id', 'stock_movements' => 'm.branch_id', 'invoices' => 'i.branch_id', 'supplier_bills' => 'b.branch_id', 'journal_lines' => 'jl.branch_id'][$dataset] ?? 's.branch_id';
        if ($branches !== null) {
            $q->whereIn($branchCol, $branches);
        }
        if (! empty($def['branch_id'])) {
            $q->where($branchCol, $def['branch_id']);
        }
        if ($dateCol) {
            $q->whereRaw(($local ? "{$local}::date" : $dateCol).' BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()]);
        }
        foreach ((array) ($def['filters'] ?? []) + $drill as $dim => $value) {
            if (isset($fields['dims'][$dim]) && (string) $value !== '') {
                $q->whereRaw('('.$sub($fields['dims'][$dim][1]).')::text '.($drill && array_key_exists($dim, $drill) ? '=' : 'ILIKE').' ?',
                    [$drill && array_key_exists($dim, $drill) ? (string) $value : '%'.$value.'%']);
            }
        }

        $grouped = $measures && ! $drill;
        $selects = array_map(fn ($d) => $sub($fields['dims'][$d][1])." AS \"d_{$d}\"", $dims);
        if ($grouped) {
            $selects = array_merge($selects, array_map(fn ($m) => $fields['measures'][$m][1]." AS \"m_{$m}\"", $measures));
            if ($dims) {
                $q->groupByRaw(implode(', ', range(1, count($dims))));
            }
        } else {
            $selects[] = $fields['detail'][0].' AS "_id"';
            foreach (array_slice($fields['detail'], 1, null, true) as $key => [$lbl, $sql]) {
                $selects[] = "{$sql} AS \"x_{$key}\"";
            }
            foreach ($measures as $m) { // detail rows: the figure for each row (the aggregate of one row)
                $agg = $fields['measures'][$m][1];
                $selects[] = (str_starts_with($agg, 'COUNT') ? '1' : preg_replace('/^(SUM|AVG)\((.*)\)$/s', '$2', $agg))." AS \"m_{$m}\"";
            }
        }
        $q->selectRaw(implode(', ', $selects));
        $sort = (string) ($def['sort'] ?? '');
        $dir = ($def['direction'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        if ($grouped && in_array($sort, $measures, true)) {
            $q->orderByRaw("\"m_{$sort}\" {$dir} NULLS LAST");
        } elseif (in_array($sort, $dims, true)) {
            $q->orderByRaw("\"d_{$sort}\" {$dir}");
        } elseif ($dims) {
            $q->orderByRaw('1 '.(in_array($dims[0], ['date', 'week', 'month', 'hour'], true) ? 'ASC' : $dir));
        }
        $limit = max(1, min(self::MAX_ROWS, (int) ($def['limit'] ?? self::MAX_ROWS)));
        $result = $q->limit($limit + 1)->get();
        $truncated = $result->count() > $limit;
        $result = $result->take($limit);

        $money = fn ($v) => Money::display((int) round((float) $v), $tenant->currency_code);
        $headings = array_merge(array_map(fn ($d) => $fields['dims'][$d][0], $dims),
            $grouped ? [] : array_map(fn ($x) => $x[0], array_slice($fields['detail'], 1, null, true)), array_map(fn ($m) => $fields['measures'][$m][0], $measures));
        $rows = [];
        $links = [];
        $raw = [];
        foreach ($result as $r) {
            $r = (array) $r;
            $rows[] = array_merge(array_map(fn ($d) => (string) $r["d_{$d}"], $dims),
                $grouped ? [] : array_map(fn ($k) => (string) $r["x_{$k}"], array_keys(array_slice($fields['detail'], 1, null, true))),
                array_map(fn ($m) => $fields['measures'][$m][2] ? $money($r["m_{$m}"]) : rtrim(rtrim(number_format((float) $r["m_{$m}"], 3, '.', ','), '0'), '.'), $measures));
            $links[] = ! $grouped && $link && isset($r['_id']) ? str_replace('{id}', $r['_id'], $link) : null;
            $raw[] = array_combine(array_map(fn ($d) => $d, $dims), array_map(fn ($d) => (string) $r["d_{$d}"], $dims));
        }
        if ($grouped && $measures && $rows) { // totals row
            $totals = [];
            foreach ($measures as $m) {
                $sum = array_sum(array_map(fn ($r) => (float) $r["m_{$m}"], array_map(fn ($x) => (array) $x, $result->all())));
                $totals[] = str_starts_with($fields['measures'][$m][1], 'AVG') || str_contains($fields['measures'][$m][1], 'DISTINCT') ? ''
                    : ($fields['measures'][$m][2] ? $money($sum) : rtrim(rtrim(number_format($sum, 3, '.', ','), '0'), '.'));
            }
            $rows[] = array_merge($dims ? array_merge(['Total'], array_fill(0, count($dims) - 1, '')) : [], $totals);
            $links[] = null;
            $raw[] = null;
        }

        return ['title' => $label, 'headings' => $headings, 'rows' => $rows, 'links' => $links, 'raw' => $raw,
            'period' => $dateCol ? "{$from->toDateString()} to {$to->toDateString()}" : 'now', 'truncated' => $truncated];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public static function range(string $period, ?string $from, ?string $to, string $tz): array
    {
        $today = CarbonImmutable::now($tz)->startOfDay();

        return match ($period) {
            'today' => [$today, $today],
            'yesterday' => [$today->subDay(), $today->subDay()],
            'this_week' => [$today->startOfWeek(), $today],
            'last_week' => [$today->subWeek()->startOfWeek(), $today->subWeek()->endOfWeek()->startOfDay()],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'last_7' => [$today->subDays(6), $today],
            'last_30' => [$today->subDays(29), $today],
            'this_year' => [$today->startOfYear(), $today],
            'last_year' => [$today->subYear()->startOfYear(), $today->subYear()->endOfYear()->startOfDay()],
            'custom' => [CarbonImmutable::parse($from ?: $today->toDateString(), $tz)->startOfDay(), CarbonImmutable::parse($to ?: $today->toDateString(), $tz)->startOfDay()],
            default => [$today->startOfMonth(), $today],
        };
    }

    private function base(string $dataset, string $tenantId): Builder
    {
        return match ($dataset) {
            'sales_lines' => DB::table('sale_lines as l')->join('sales as s', 's.id', '=', 'l.sale_id')->join('branches as br', 'br.id', '=', 's.branch_id')
                ->join('products as p', 'p.id', '=', 'l.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')->leftJoin('brands as bd', 'bd.id', '=', 'p.brand_id')
                ->leftJoin('customers as cu', 'cu.id', '=', 's.customer_id')->where('s.tenant_id', $tenantId)->where('s.status', 'posted'),
            'receipts' => DB::table('sales as s')->join('branches as br', 'br.id', '=', 's.branch_id')->leftJoin('customers as cu', 'cu.id', '=', 's.customer_id')
                ->where('s.tenant_id', $tenantId)->where('s.status', 'posted'),
            'payments' => DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')->join('branches as br', 'br.id', '=', 's.branch_id')
                ->where('s.tenant_id', $tenantId)->where('s.status', 'posted'),
            'stock_levels' => DB::table('stock_levels as sl')->join('warehouses as w', 'w.id', '=', 'sl.warehouse_id')->join('branches as br', 'br.id', '=', 'w.branch_id')
                ->join('products as p', 'p.id', '=', 'sl.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')->leftJoin('brands as bd', 'bd.id', '=', 'p.brand_id')
                ->where('sl.tenant_id', $tenantId),
            'stock_movements' => DB::table('stock_movements as m')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')->join('branches as br', 'br.id', '=', 'm.branch_id')
                ->join('products as p', 'p.id', '=', 'm.product_id')->where('m.tenant_id', $tenantId),
            'invoices' => DB::table('invoices as i')->join('branches as br', 'br.id', '=', 'i.branch_id')->where('i.tenant_id', $tenantId),
            'supplier_bills' => DB::table('supplier_bills as b')->join('branches as br', 'br.id', '=', 'b.branch_id')->join('suppliers as su', 'su.id', '=', 'b.supplier_id')->where('b.tenant_id', $tenantId),
            'journal_lines' => DB::table('journal_lines as jl')->join('journal_entries as e', 'e.id', '=', 'jl.journal_entry_id')->join('accounts as a', 'a.id', '=', 'jl.account_id')
                ->leftJoin('branches as br', 'br.id', '=', 'jl.branch_id')->where('e.tenant_id', $tenantId),
        };
    }
}
