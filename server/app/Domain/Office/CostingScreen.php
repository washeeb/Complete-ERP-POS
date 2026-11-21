<?php

namespace App\Domain\Office;

use App\Domain\Finance\GeneralLedger;
use App\Domain\Inventory\StockCostRuns;
use App\Domain\Purchasing\SpendAnalytics;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Filament\Pages\EstimatedCosts;
use App\Filament\Pages\SpendAnalyticsPage;
use App\Filament\Resources\LandedCosts\LandedCostResource;
use App\Filament\Resources\StockCostRuns\StockCostRunResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\Brand;
use App\Models\Category;
use App\Models\GoLivePlan;
use App\Models\GoodsReceipt;
use App\Models\LandedCost;
use App\Models\LandedCostLine;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\StockCostRun;
use App\Models\StockCostRunLine;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The last of Stock and Purchasing in the new back office (part 19 of the rewrite), as the current pages: Landed costs
 * (freight and duty spread over deliveries), Cost runs (a cost worked out from the selling price, previewed, then
 * posted or undone), Estimated against real costs, and Spend analytics. Same queries (the resources' and pages'), same
 * rules: LandedCostService and StockCostRuns (CostingController).
 */
final class CostingScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    private function m(): array
    {
        $c = $this->currency();

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    /** A cost per unit kept to four places of a minor unit, shown in the currency to four decimals (as the current page). */
    private function perUnit(mixed $minor): ?string
    {
        return $minor === null ? null : number_format((float) $minor / (10 ** Money::exponent($this->currency())), 4);
    }

    private static function qty(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    }

    // -------------------------------------------------------------------------------------------- Landed costs

    public static function canLanded(): bool
    {
        return LandedCostResource::canViewAny();
    }

    public static function canSpread(): bool
    {
        return Erp::canAnywhere('payables.manage') && config('erp.node.role') !== 'branch';
    }

    public function landedQuery(): Builder
    {
        return LandedCostResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q (number or a charge's description), page, per */
    public function landed(array $p): array
    {
        $q = $this->landedQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('landed_costs.number', 'ilike', "%{$s}%")
                ->orWhereHas('charges', fn ($c) => $c->where('description', 'ilike', "%{$s}%")));
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'can_spread' => self::canSpread(),
            'rows' => $q->orderByDesc('landed_costs.posted_at')->forPage($page, $per)->get()->map(fn (LandedCost $r) => ['id' => $r->id, 'number' => $r->number,
                'date' => $r->posted_at?->toDateString(), 'charges' => $r->charges->pluck('description')->implode('; '),
                'method' => $r->method === 'value' ? 'By value' : 'By quantity', 'total' => (int) $r->total_minor, 'to_stock' => (int) $r->to_stock_minor,
                'to_cogs' => (int) $r->to_cogs_minor, 'by' => $r->created_by_name])->all()];
    }

    /** "Per product": each product's share, into stock, cost of sales, and its cost per unit before and after. */
    public function landedLines(LandedCost $r): array
    {
        return $this->m() + ['number' => $r->number, 'note' => $r->note,
            'charges' => $r->charges->map(fn ($c) => ['kind' => LandedCost::KINDS[$c->kind] ?? $c->kind, 'description' => $c->description, 'amount' => (int) $c->amount_minor])->all(),
            'lines' => $r->lines()->with('product')->get()->map(fn (LandedCostLine $l) => ['product' => $l->product?->name ?? '—', 'allocated' => (int) $l->allocated_minor,
                'to_stock' => (int) $l->to_stock_minor, 'to_cogs' => (int) $l->to_cogs_minor, 'before' => $this->perUnit($l->avg_before), 'after' => $this->perUnit($l->avg_after)])->all()];
    }

    /** The spread form: posted deliveries of the last six months, the ways to spread and the kinds of charge. */
    public function spreadForm(): array
    {
        return $this->m() + ['methods' => self::choices(LandedCost::METHODS), 'kinds' => self::choices(LandedCost::KINDS),
            'receipts' => GoodsReceipt::with('supplier')->where('status', 'posted')->where('posted_at', '>=', now()->subMonths(6))->orderByDesc('posted_at')->limit(300)->get()
                ->map(fn (GoodsReceipt $g) => ['id' => $g->id, 'label' => "{$g->number} · ".($g->supplier?->name ?? '—').' · '.$g->posted_at?->format('j M').' · '.FinanceUi::money((int) $g->net_minor)])->all()];
    }

    // -------------------------------------------------------------------------------------------- Cost runs

    public const RUN_TONE = ['draft' => 'informative', 'submitted' => 'warning', 'posted' => 'success', 'cancelled' => 'subtle', 'reversed' => 'subtle'];

    public const LINE_TONE = ['estimate' => 'success', 'overwrite' => 'danger', 'top_up' => 'informative', 'skipped' => 'warning'];

    public static function canRuns(): bool
    {
        return StockCostRunResource::canViewAny();
    }

    public function runsQuery(): Builder
    {
        return StockCostRunResource::getEloquentQuery();
    }

    private static function factor(StockCostRun $r): string
    {
        return StockCostRun::factorLabel($r->method, $r->value_bp);
    }

    /** @param  array<string, mixed>  $p  q (number), status, page, per */
    public function runs(array $p): array
    {
        $q = $this->runsQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where('stock_cost_runs.number', 'ilike', "%{$s}%");
        }
        if (array_key_exists($p['status'] ?? '', StockCostRun::STATUSES)) {
            $q->where('stock_cost_runs.status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $waiting = (clone $q)->reorder()->where('stock_cost_runs.status', 'submitted')->count();

        return $this->m() + ['total' => $total, 'waiting' => $waiting, 'page' => $page, 'per' => $per, 'statuses' => self::choices(StockCostRun::STATUSES),
            'can_create' => StockCostRunResource::canCreate(),
            'rows' => $q->orderByDesc('stock_cost_runs.created_at')->forPage($page, $per)->get()->map(fn (StockCostRun $r) => ['id' => $r->id, 'number' => $r->number,
                'status' => $r->status, 'status_label' => StockCostRun::STATUSES[$r->status] ?? $r->status, 'tone' => self::RUN_TONE[$r->status] ?? 'informative',
                'factor' => self::factor($r), 'effective_on' => $r->effective_on?->toDateString(), 'stock_value' => (int) $r->stock_value_minor,
                'catch_up' => (int) $r->catch_up_minor, 'lines' => (int) $r->lines_count, 'skipped' => (int) $r->skipped_count, 'by' => $r->created_by_name])->all()];
    }

    /** One run: the preview (or what it did), its products a page at a time, and the current page's buttons. */
    public function run(StockCostRun $r, array $p = []): array
    {
        $r->loadMissing('priceList', 'account');
        $svc = app(StockCostRuns::class);
        $manage = $r->isOpen() && Erp::canAnywhere('stock.cost.manage');
        $lines = $r->lines()->with('product', 'warehouse');
        if (array_key_exists($p['kind'] ?? '', StockCostRunLine::KINDS)) {
            $lines->where('kind', $p['kind']);
        }
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $lines->whereHas('product', fn ($w) => $w->where('name', 'ilike', "%{$s}%")->orWhere('sku', 'ilike', "%{$s}%"));
        }
        [$per, $page] = self::page($p);
        $count = (clone $lines)->count();
        $money = fn ($v) => $v === null ? null : (int) round((float) $v);
        $factor = self::factor($r).collect($r->overrides ?? [])->map(fn ($o) => ', '.$o['name'].' '.StockCostRun::factorLabel($r->method, $o['value_bp']))->implode('');

        return $this->m() + ['id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'status_label' => StockCostRun::STATUSES[$r->status] ?? $r->status,
            'tone' => self::RUN_TONE[$r->status] ?? 'informative', 'done' => in_array($r->status, ['posted', 'reversed'], true),
            'nothing' => $r->isOpen() && (int) $r->lines_count === 0 && (int) $r->already_costed_count > 0 && ! $r->overwrite ? (int) $r->already_costed_count : null,
            'stock_value' => (int) $r->stock_value_minor, 'catch_up' => (int) $r->catch_up_minor, 'lines_count' => (int) $r->lines_count,
            'skipped' => (int) $r->skipped_count, 'already_costed' => (int) $r->already_costed_count, 'factor' => $factor,
            'tax_mode' => StockCostRun::TAX_MODES[$r->tax_mode] ?? $r->tax_mode, 'price_list' => $r->priceList?->name, 'effective_on' => $r->effective_on?->toDateString(),
            'account' => $r->account?->name, 'prepared_by' => $r->created_by_name, 'posted_by' => $r->posted_by_name, 'reason' => $r->reason, 'note' => $r->note,
            'reverses' => $r->reverses_id ? ['number' => StockCostRun::whereKey($r->reverses_id)->value('number'), 'open' => "/office/cost-runs/{$r->reverses_id}"] : null,
            'reversed_by' => $r->status === 'reversed' && ($back = StockCostRun::where('reverses_id', $r->id)->first(['id', 'number']))
                ? ['number' => $back->number, 'open' => "/office/cost-runs/{$back->id}"] : null,
            'kinds' => self::choices(StockCostRunLine::KINDS),
            'products' => ['total' => $count, 'page' => $page, 'per' => $per,
                'rows' => $lines->orderByDesc('stock_value_minor')->orderBy('id')->forPage($page, $per)->get()->map(fn (StockCostRunLine $l) => ['id' => $l->id,
                    'product' => $l->product?->name ?? '—', 'sku' => $l->product?->sku, 'store' => $l->warehouse?->name, 'kind' => $l->kind,
                    'kind_label' => StockCostRunLine::KINDS[$l->kind] ?? $l->kind, 'tone' => self::LINE_TONE[$l->kind] ?? 'informative', 'why' => $l->skip_reason,
                    'on_hand' => self::qty($l->on_hand), 'price' => $money($l->price_minor), 'factor' => $l->value_bp ? StockCostRun::factorLabel($r->method, $l->value_bp) : null,
                    'old_cost' => $money($l->old_avg_minor), 'new_cost' => $money($l->new_cost_minor), 'stock_value' => $money($l->stock_value_minor),
                    'sold' => self::qty($l->sold_qty), 'catch_up' => $money($l->catch_up_minor)])->all()],
            'actions' => [
                'recalculate' => $manage,
                'replace' => $manage && ! $r->overwrite && (int) $r->already_costed_count > 0,
                'submit' => $r->status === 'draft' && Erp::canAnywhere('stock.cost.manage') && $svc->twoPeople($r->tenant_id),
                'post' => $r->isOpen() && Erp::canAnywhere('stock.cost.approve') && ! $svc->whyNotPost(Actor::user($this->user, 'web'), $r),
                'cancel' => $manage,
                'undo' => $r->status === 'posted' && ! $r->reverses_id && Erp::canAnywhere('stock.cost.approve')]];
    }

    /** The go-live date when that month is still open, otherwise today (as the current form). */
    private function defaultDate(): string
    {
        $date = GoLivePlan::query()->value('go_live_on');
        if ($date) {
            try {
                app(GeneralLedger::class)->postingDate($this->user->tenant_id, Carbon::parse($date)->toDateString());

                return Carbon::parse($date)->toDateString();
            } catch (DomainException) {
                // that month is closed
            }
        }

        return now()->toDateString();
    }

    /** The new run form: its choices, and the products ticked on the products list (handed over in the session). */
    public function runForm(): array
    {
        $picked = (array) session()->pull('stock_cost_run.products', []);

        return $this->m() + ['methods' => self::choices(StockCostRun::METHODS), 'tax_modes' => self::choices(StockCostRun::TAX_MODES),
            'price_lists' => self::choices(PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id')->all()),
            'categories' => self::choices(Category::orderBy('name')->pluck('name', 'id')->all()), 'brands' => self::choices(Brand::orderBy('name')->pluck('name', 'id')->all()),
            'suppliers' => self::choices(Supplier::orderBy('name')->pluck('name', 'id')->all()), 'warehouses' => self::choices(Fields::warehouses('stock.cost.manage')),
            'accounts' => Erp::canAnywhere('finance.view') ? self::choices(FinanceUi::accounts(false)) : null,
            'values' => ['method' => 'divide', 'value' => '1.4', 'price_list_id' => (string) (PriceList::where('is_default', true)->value('id') ?? ''), 'tax_mode' => 'shelf',
                'effective_on' => $this->defaultDate(), 'catch_up' => true, 'overwrite' => false,
                'products' => $picked ? Product::whereIn('id', $picked)->orderBy('name')->get(['id', 'name', 'sku'])->map(fn ($x) => ['id' => $x->id, 'label' => $x->name, 'detail' => $x->sku])->all() : []]];
    }

    /** Products by name or SKU, for "Only these products". */
    public function products(string $q): array
    {
        return Product::where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('sku', 'ilike', "%{$q}%"))->orderBy('name')->limit(30)->get(['id', 'name', 'sku'])
            ->map(fn ($x) => ['id' => $x->id, 'label' => $x->name, 'detail' => $x->sku])->all();
    }

    // -------------------------------------------------------------------------------------------- Estimated costs

    public static function canEstimates(): bool
    {
        return EstimatedCosts::canAccess();
    }

    private const DELIVERED = 'EXISTS (SELECT 1 FROM goods_receipt_lines g JOIN goods_receipts gr ON gr.id = g.goods_receipt_id WHERE g.product_id = stock_cost_run_lines.product_id AND gr.status = ? AND gr.posted_at > r.posted_at)';

    /** @param  array<string, mixed>  $p  delivered (yes / no / empty), page, per */
    public function estimates(array $p): array
    {
        $row = (new EstimatedCosts)->getSubheading();
        $q = EstimatedCosts::baseQuery();
        match ($p['delivered'] ?? '') {
            'yes' => $q->whereRaw(self::DELIVERED, ['posted']),
            'no' => $q->whereRaw('NOT '.self::DELIVERED, ['posted']),
            default => null,
        };
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $money = fn ($v) => $v === null ? null : (int) round((float) $v);

        return $this->m() + ['summary' => $row, 'total' => $total, 'page' => $page, 'per' => $per,
            'rows' => EstimatedCosts::withRealCost($q)->with('product', 'warehouse')->orderByDesc('stock_cost_run_lines.created_at')->orderBy('stock_cost_run_lines.id')
                ->forPage($page, $per)->get()->map(fn (StockCostRunLine $l) => ['id' => $l->id, 'product' => $l->product?->name ?? '—', 'store' => $l->warehouse?->name,
                    'price' => $money($l->price_minor), 'estimate' => $money($l->new_cost_minor), 'real' => $money($l->real_cost),
                    'difference' => $l->real_cost && $l->new_cost_minor ? number_format(((float) $l->real_cost / $l->new_cost_minor - 1) * 100, 1).'%' : null,
                    'real_factor' => (float) $l->real_cost > 0 ? number_format((float) $l->price_minor / (float) $l->real_cost, 2) : null])->all()];
    }

    // -------------------------------------------------------------------------------------------- Spend analytics

    public static function canSpend(): bool
    {
        return SpendAnalyticsPage::canAccess();
    }

    /** @return array{0: string, 1: string, 2: ?string} */
    private static function period(array $p): array
    {
        $date = fn ($v, $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : $d;
        $branch = (string) ($p['branch'] ?? '');

        return [$date($p['from'] ?? '', now()->startOfYear()->toDateString()), $date($p['to'] ?? '', now()->toDateString()),
            array_key_exists($branch, FinanceUi::branches()) ? $branch : null];
    }

    /** @param  array<string, mixed>  $p  from, to, branch */
    public function spend(array $p): array
    {
        [$from, $to, $branch] = self::period($p);
        $a = SpendAnalytics::for($this->user->tenant_id, $from, $to, $branch);

        return $this->m() + ['from' => $from, 'to' => $to, 'branch' => $branch ?? '', 'branches' => self::choices(FinanceUi::branches()),
            'totals' => $a->totals(), 'compliance' => $a->compliance(), 'concentration' => $a->concentration(), 'variance' => $a->priceVariance(),
            'rfq' => $a->rfqSavings(), 'months' => array_values($a->byMonth()), 'suppliers' => array_values($a->bySupplier()), 'categories' => array_values($a->byCategory()),
            'departments' => array_values($a->byDepartment())];
    }

    /** The same figures as sheets, for Excel, CSV or PDF. */
    public function spendSheets(array $p): array
    {
        $d = $this->spend($p);
        $m = fn ($v) => FinanceUi::money((int) $v);
        $t = $d['totals'];
        $c = $d['compliance'];
        $where = collect($d['branches'])->firstWhere('id', $d['branch'])['label'] ?? 'All';

        return ['title' => 'Spend analytics', 'filters' => ["From: {$d['from']}", "To: {$d['to']}", "Branch: {$where}"], 'sheets' => [
            ['title' => 'Summary', 'headings' => ['Measure', 'Value'], 'rows' => [
                ['Spend (before VAT)', $m($t['spend'])], ['Bills', (string) $t['bills']], ['Suppliers', (string) $t['suppliers']],
                ['Bought on an approved order', "{$c['on_order_pct']} %"], ['Under contracts', "{$c['on_contract_pct']} %"],
                ['Biggest supplier', "{$d['concentration']['top1']} %"], ['Biggest five suppliers', "{$d['concentration']['top5']} %"],
                ['Billed above order prices', $m($d['variance']['above'])], ['Saved by asking for quotes', $m($d['rfq']['saved'])]]],
            ['title' => 'By month', 'headings' => ['Month', 'Spend'], 'rows' => array_map(fn ($r) => [$r['month'], $m($r['spend'])], $d['months'])],
            ['title' => 'By supplier', 'headings' => ['Supplier', 'Bills', 'Spend', 'Share'], 'rows' => array_map(fn ($r) => [$r['name'], (string) $r['bills'], $m($r['spend']), "{$r['share']} %"], $d['suppliers'])],
            ['title' => 'By category', 'headings' => ['Category', 'Spend', 'Share'], 'rows' => array_map(fn ($r) => [$r['name'], $m($r['spend']), "{$r['share']} %"], $d['categories'])],
            ['title' => 'How it was bought', 'headings' => ['Bought', 'Spend'], 'rows' => [['On an approved purchase order', $m($c['on_order'])],
                ['of which under a contract or blanket order', $m($c['on_contract'])], ['Received without an order', $m($c['received_no_order'])],
                ['Billed with no order (charges, services)', $m($c['no_order'])]]],
            ['title' => 'By department', 'headings' => ['Department', 'Spend'], 'rows' => array_map(fn ($r) => [$r['name'], $m($r['spend'])], $d['departments'])],
        ]];
    }
}
