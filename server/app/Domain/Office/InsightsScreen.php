<?php

namespace App\Domain\Office;

use App\Domain\Analytics\CashFlowForecaster;
use App\Domain\Analytics\CustomerInsights;
use App\Domain\Analytics\DemandForecaster;
use App\Domain\Analytics\LossPrevention;
use App\Domain\Analytics\PricingAdvisor;
use App\Domain\Analytics\StockHealth;
use App\Filament\Pages\Insights as Page;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Insights in the new back office (release 7 of the rewrite): the built-in analytics of the current page — demand
 * forecast and reorder, stock health, customers, pricing, cash flow and loss prevention — with the same tabs (cash,
 * customers and pricing need company-wide access; loss prevention its own permission) and the same warehouse choice.
 * Each tab comes back as report tables (ReportTables), so the export matches the screen.
 */
final class InsightsScreen
{
    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return Page::canAccess();
    }

    /** @return array<string, string> the tabs this person may open */
    public static function tabs(): array
    {
        $tabs = Page::TABS;
        if (! Erp::canAnywhere('insights.loss_prevention')) {
            unset($tabs['loss']);
        }
        if (Erp::branchIds('insights.view') !== null) { // company-wide money and customer figures need a company-wide grant
            unset($tabs['cash'], $tabs['customers'], $tabs['pricing']);
        }

        return $tabs;
    }

    private function m(int|float|null $minor): string
    {
        return FinanceUi::money((int) round((float) $minor));
    }

    private function q(float|int|string|null $v): string
    {
        return Fields::qty((string) $v);
    }

    private static function bad(string $text, bool $bad, bool $strong = false): array
    {
        return ['t' => $text, 'tone' => $bad ? 'bad' : null, 'strong' => $strong];
    }

    /** @param  array<string, mixed>  $p  tab, warehouse */
    public function show(array $p): array
    {
        $tabs = self::tabs();
        $tab = isset($tabs[$p['tab'] ?? '']) ? $p['tab'] : (string) array_key_first($tabs);
        $warehouses = Fields::warehouses('insights.view');
        $warehouse = isset($warehouses[$p['warehouse'] ?? '']) ? (string) $p['warehouse'] : (string) array_key_first($warehouses);
        $tenant = $this->user->tenant_id;
        $scope = $warehouse !== '' ? [$warehouse] : [];

        $tables = match ($tab) {
            'stock' => $this->stock($tenant, $scope, array_keys($warehouses)),
            'customers' => $this->customers($tenant),
            'pricing' => $this->pricing($tenant, $scope),
            'cash' => $this->cash($tenant),
            'loss' => $this->loss($tenant),
            default => $this->forecast($warehouse),
        };

        return ['tab' => $tab, 'tabs' => PurchaseOrdersScreen::choices($tabs), 'title' => Page::TABS[$tab] ?? '', 'warehouse' => $warehouse,
            'warehouses' => PurchaseOrdersScreen::choices($warehouses), 'by_warehouse' => in_array($tab, ['forecast', 'stock', 'pricing'], true), 'tables' => $tables];
    }

    private function forecast(string $warehouse): array
    {
        $rows = $warehouse !== '' ? app(DemandForecaster::class)->forWarehouse($warehouse) : [];

        return [ReportTables::table('Demand forecast and reorder', ['SKU', 'Product', '#Sold a day', '#Next 7 days', '#Next 28 days', '#Trend', '#On hand', '#On order', '#Days of cover', 'Runs out about', '#Suggested order', '#Suggested min / max'],
            ReportTables::rows($rows, fn ($f) => [$f['sku'], $f['name'], (string) $f['avg_daily'], (string) $f['forecast_7'], (string) $f['forecast_28'],
                $f['trend_pct'] === null ? '—' : sprintf('%+d %%', $f['trend_pct']), $this->q($f['on_hand']), $this->q($f['on_order']), (string) ($f['days_cover'] ?? '—'),
                $f['stockout_on'] ?? '—', ['t' => $f['suggested_order'] ? (string) $f['suggested_order'] : '', 'strong' => true], "{$f['suggested_min']} / {$f['suggested_max']}"]),
            hint: 'From the last 12 weeks of till sales: recent level, weekday pattern, paydays (25th–3rd) and public holidays. Suggested order covers the supplier\'s lead time plus a week, with safety stock. Shortest cover first.',
            empty: 'No sales in the last 12 weeks at this warehouse.')];
    }

    private function stock(string $tenant, array $scope, array $all): array
    {
        $health = app(StockHealth::class);
        $h = $scope ? $health->report($tenant, $scope) : ['dead' => [], 'overstock' => [], 'expiry' => []];

        return [
            ReportTables::table('May expire unsold', ['Product', 'Batch', 'Expires', '#Days left', '#On hand', '#At risk', '#Value at cost', '#Suggested markdown'],
                ReportTables::rows($h['expiry'], fn ($e) => [$e['name'], $e['batch_no'], $e['expires_on'], (string) $e['days_left'], $this->q($e['on_hand']), (string) $e['at_risk'],
                    $this->m($e['value_minor']), "{$e['markdown_pct']} %"]),
                hint: 'Batches that will not sell before their expiry at the current rate (earliest expiry sold first). Mark down, or move to a branch that sells faster.',
                empty: 'Nothing at risk in the next 90 days.'),
            ReportTables::table('Dead stock', ['SKU', 'Product', '#On hand', '#Value at cost', 'Last sold'],
                ReportTables::rows($h['dead'], fn ($d) => [$d['sku'], $d['name'], $this->q($d['on_hand']), $this->m($d['value_minor']), $d['last_sold'] ?? 'Never here']),
                hint: 'In stock but not sold for 90 days or more.', empty: 'No dead stock.'),
            ReportTables::table('Overstock', ['SKU', 'Product', '#On hand', '#Days of stock', '#Excess', '#Excess value'],
                ReportTables::rows($h['overstock'], fn ($o) => [$o['sku'], $o['name'], $this->q($o['on_hand']), (string) $o['days_cover'], (string) $o['excess'], $this->m($o['excess_value_minor'])]),
                hint: 'More than 120 days of stock at the current rate; the excess is what is above two months.', empty: 'No overstock.'),
            ReportTables::table('Transfers between branches', ['Product', 'From', 'To', '#Quantity', '#Days of cover where needed'],
                ReportTables::rows($all ? $health->report($tenant, $all)['transfers'] : [], fn ($t) => [$t['name'], $t['from'], $t['to'], (string) $t['qty'], (string) $t['to_days_cover']]),
                hint: 'Stock sitting at one warehouse (over 60 days of cover) that another is about to run out of (under a week). Create the transfer under Stock → Transfers.',
                empty: 'No transfers suggested.'),
        ];
    }

    private function customers(string $tenant): array
    {
        $c = app(CustomerInsights::class);

        return [
            ReportTables::table('Segments (last 12 months)', ['Segment', '#Customers', '#Spent'],
                ReportTables::rows($c->segments($tenant), fn ($s) => [$s['segment'], (string) $s['customers'], $this->m($s['spend_minor'])]),
                hint: 'By how recently, how often and how much each customer bought (till sales with a customer, and invoices).', empty: 'No customer sales yet.'),
            ReportTables::table('Regulars who have not been back', ['Customer', 'Code', 'Last visit', '#Usual gap (days)', '#Days since', '#Visits', '#Spent'],
                ReportTables::rows(array_slice($c->churnRisk($tenant), 0, 50), fn ($r) => [$r['name'], $r['code'], $r['last'], (string) $r['usual_gap_days'], (string) $r['days_since'],
                    (string) $r['visits'], $this->m($r['spend_minor'])]),
                hint: 'Customers with 3 or more visits whose time since the last is more than twice their usual gap (and over 30 days). A call or SMS offer may bring them back.',
                empty: 'No regular customer is overdue.'),
            ReportTables::table('Bought together (last 90 days)', ['Product', 'With', '#Receipts with both', '#Confidence', '#Lift'],
                ReportTables::rows($c->baskets($tenant), fn ($b) => [$b['a'], $b['b'], (string) $b['together'], "{$b['confidence_pct']} %", (string) $b['lift']]),
                hint: 'Pairs on the same receipt. Lift above 1 means they go together more than chance: place them side by side or bundle them.', empty: 'Not enough receipts yet.'),
        ];
    }

    private function pricing(string $tenant, array $scope): array
    {
        $p = app(PricingAdvisor::class);

        return [
            ReportTables::table('Low margins', ['SKU', 'Product', '#Price', '#Average cost', '#Margin', '#Price for target margin'],
                ReportTables::rows($p->margins($tenant), fn ($m) => [$m['sku'], $m['below_cost'] ? self::bad("{$m['name']} — below cost", true) : $m['name'], $this->m($m['price_minor']),
                    $this->m($m['cost_minor']), "{$m['margin_pct']} %", $this->m($m['price_for_target_minor'])]),
                hint: 'Default price list, price before tax against average cost. Change prices under Catalog → Price lists.', empty: 'Every priced product meets the margin target.'),
            ReportTables::table('Markdown suggestions', ['SKU', 'Product', '#On hand', 'Why', '#Suggested markdown'],
                ReportTables::rows($scope ? $p->markdowns($tenant, $scope) : [], fn ($m) => [$m['sku'], $m['name'], $this->q($m['on_hand']), $m['reason'], "{$m['markdown_pct']} %"]),
                hint: 'Slow movers and dead stock at this warehouse. Near-expiry stock is under Stock health.', empty: 'No slow movers.'),
            ReportTables::table('Promotion results (last 180 days)', ['Promotion', 'From', 'To', '#Before (a day)', '#During (a day)', '#Uplift', '#Extra units', '#Discount given'],
                ReportTables::rows($p->promotionUplift($tenant), fn ($r) => [$r['promotion'], $r['from'], $r['to'], (string) $r['before_daily'], (string) $r['during_daily'],
                    $r['uplift_pct'] === null ? '—' : "{$r['uplift_pct']} %", (string) $r['extra_units'], $this->m($r['discount_minor'])]),
                hint: 'Units sold a day during each promotion against the 28 days before, and the discount given.', empty: 'No product promotions in the last 180 days.'),
        ];
    }

    private function cash(string $tenant): array
    {
        $f = app(CashFlowForecaster::class)->forecast($tenant);

        return [ReportTables::table('Cash-flow forecast (8 weeks)', ['Week of', '#Start', '#Till takings', '#From customers', '#To suppliers', '#Payroll', '#Expenses', '#End'],
            ReportTables::rows($f['weeks'], fn ($w) => [$w['week'], $this->m($w['opening_minor']), $this->m($w['till_minor']), $this->m($w['customers_minor']), $this->m($w['suppliers_minor']),
                $this->m($w['payroll_minor']), $this->m($w['expenses_minor']), self::bad($this->m($w['closing_minor']), $w['closing_minor'] < 0, true)]),
            hint: 'Money now, plus till takings at the recent weekly average and customer invoices by due date, less supplier bills by due date, payroll at month end (last run) and expense claims at their recent average. Overdue items fall in the first week. A planning guide, not a promise.')];
    }

    private function loss(string $tenant): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $rows = app(LossPrevention::class)->cashiers($tenant, $today->subDays(30), $today->addDay(), Erp::branchIds('insights.loss_prevention'));

        return [ReportTables::table('Loss prevention (last 30 days)', ['Cashier', '#Receipts', '#Takings', '#Refunds', '#Refund value', '#No-receipt refunds', '#Discounts', '#Shifts (short)',
            '#Over / short total', '#Voids', '#No-sale opens', '#Risk', 'Why'],
            ReportTables::rows($rows, fn ($r) => [$r['name'], (string) $r['receipts'], $this->m($r['takings_minor']), (string) $r['refunds'], $this->m($r['refund_value_minor']),
                (string) $r['no_receipt_refunds'], $this->m($r['discount_minor']), "{$r['shifts']} ({$r['short_shifts']})", $this->m($r['over_short_minor']), (string) $r['voids'],
                (string) $r['no_sales'], self::bad((string) ($r['risk'] ?? '—'), ($r['risk'] ?? 0) >= 50, true), implode(', ', $r['flags'])]),
            hint: 'Each cashier compared with the others: refunds, refunds without a receipt, discounts, drawer over / short at close, kitchen voids, drawer opened without a sale. The risk score (0–100) says how unusual the pattern is — a reason to look at receipts and CCTV, not proof. Cashiers with fewer than 20 receipts are not scored.',
            empty: 'No till sales in the last 30 days.')];
    }

    /** The tab as Exporter sheets, and the filters for the PDF header and the audit log. */
    public function sheets(array $p): array
    {
        $d = $this->show($p);
        $filters = ["View: {$d['title']}"];
        if ($d['by_warehouse']) {
            $filters[] = 'Warehouse: '.(collect($d['warehouses'])->firstWhere('id', $d['warehouse'])['label'] ?? '—');
        }

        return ['title' => "Insights — {$d['title']}", 'sheets' => ReportTables::sheets($d['tables'], $d['title']), 'filters' => $filters];
    }
}
