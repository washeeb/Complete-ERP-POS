<?php

namespace App\Domain\Purchasing;

use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Spend analytics (procurement stage 5): where the money goes, from posted supplier bills (before VAT, by bill date),
 * and how well buying is controlled:
 *   - by supplier (with how much the biggest one and five take), by category (the product's category for goods, the
 *     account for services and charges), by department, by month;
 *   - on an order or not ("off-order" spend skips approval), and how much went through contracts / blanket orders;
 *   - bills above or below the order price, and what requests for quotation saved (each line awarded against the
 *     average of the other prices quoted for it).
 * Read only; nothing is stored.
 */
final class SpendAnalytics
{
    public function __construct(private readonly string $tenantId, private readonly string $from, private readonly string $to, private readonly ?string $branchId = null) {}

    public static function for(string $tenantId, string $from, string $to, ?string $branchId = null): self
    {
        return new self($tenantId, $from, $to, $branchId);
    }

    private function bills()
    {
        return DB::table('supplier_bills as b')->where('b.tenant_id', $this->tenantId)->where('b.status', 'posted')
            ->whereBetween('b.bill_date', [$this->from, $this->to])->when($this->branchId, fn ($q) => $q->where('b.branch_id', $this->branchId));
    }

    /** Every billed line with what it was for: supplier, category, department, how it was ordered. */
    private function lines(): Collection
    {
        return $this->lines ??= DB::table('supplier_bill_lines as l')->joinSub($this->bills()->select('b.id', 'b.supplier_id', 'b.bill_date'), 'b', 'b.id', '=', 'l.supplier_bill_id')
            ->leftJoin('goods_receipt_lines as g', 'g.id', '=', 'l.goods_receipt_line_id')
            ->leftJoin('purchase_order_lines as o', 'o.id', '=', DB::raw('COALESCE(l.purchase_order_line_id, g.purchase_order_line_id)'))
            ->leftJoin('products as p', 'p.id', '=', DB::raw('COALESCE(l.product_id, g.product_id)'))
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('accounts as a', 'a.id', '=', 'l.account_id')
            ->leftJoin('departments as d', 'd.id', '=', DB::raw('COALESCE(l.department_id, o.department_id)'))
            ->get(['b.supplier_id', 'b.bill_date', 'l.net_minor', 'l.goods_receipt_line_id', 'o.id as order_line_id', 'o.agreement_line_id',
                'p.id as product_id', 'c.name as category', 'a.code as account_code', 'a.name as account_name', 'd.name as department']);
    }

    private ?Collection $lines = null;

    /** @return array{spend: int, bills: int, suppliers: int} */
    public function totals(): array
    {
        return ['spend' => (int) $this->lines()->sum('net_minor'), 'bills' => $this->bills()->count(), 'suppliers' => $this->lines()->pluck('supplier_id')->unique()->count()];
    }

    /** @return list<array{name: string, spend: int, share: float, bills: int}> biggest first */
    public function bySupplier(int $top = 15): array
    {
        $total = max(1, $this->totals()['spend']);
        $names = DB::table('suppliers')->whereIn('id', $this->lines()->pluck('supplier_id')->unique())->pluck('name', 'id');
        $bills = $this->bills()->groupBy('b.supplier_id')->selectRaw('b.supplier_id, COUNT(*) AS n')->pluck('n', 'supplier_id');

        return $this->lines()->groupBy('supplier_id')->map(fn ($g, $id) => ['name' => $names[$id] ?? '—', 'spend' => (int) $g->sum('net_minor'),
            'share' => round(100 * $g->sum('net_minor') / $total, 1), 'bills' => (int) ($bills[$id] ?? 0)])->sortByDesc('spend')->take($top)->values()->all();
    }

    /** How much the biggest supplier, and the biggest five, take (a single source is a risk; many tiny ones waste effort). */
    public function concentration(): array
    {
        $total = max(1, $this->totals()['spend']);
        $sorted = $this->lines()->groupBy('supplier_id')->map(fn ($g) => (int) $g->sum('net_minor'))->sortDesc()->values();

        return ['top1' => round(100 * ($sorted[0] ?? 0) / $total, 1), 'top5' => round(100 * $sorted->take(5)->sum() / $total, 1)];
    }

    /** @return list<array{name: string, spend: int, share: float}> */
    public function byCategory(int $top = 15): array
    {
        $total = max(1, $this->totals()['spend']);

        return $this->lines()->groupBy(fn ($l) => $l->product_id ? ($l->category ?? 'Goods without a category') : trim(($l->account_code ?? '').' '.($l->account_name ?? 'Other charges')))
            ->map(fn ($g, $name) => ['name' => $name, 'spend' => (int) $g->sum('net_minor'), 'share' => round(100 * $g->sum('net_minor') / $total, 1)])
            ->sortByDesc('spend')->take($top)->values()->all();
    }

    /** Services, non-stock items and charges by department (goods for resale have none). */
    public function byDepartment(): array
    {
        return $this->lines()->filter(fn ($l) => ! $l->product_id)->groupBy(fn ($l) => $l->department ?? 'No department given')
            ->map(fn ($g, $name) => ['name' => $name, 'spend' => (int) $g->sum('net_minor')])->sortByDesc('spend')->values()->all();
    }

    /** @return list<array{month: string, spend: int}> the twelve months up to the end of the period */
    public function byMonth(): array
    {
        $end = Carbon::parse($this->to)->startOfMonth();
        $start = $end->copy()->subMonths(11);
        $rows = DB::table('supplier_bills as b')->where('b.tenant_id', $this->tenantId)->where('b.status', 'posted')
            ->whereBetween('b.bill_date', [$start->toDateString(), $end->copy()->endOfMonth()->toDateString()])->when($this->branchId, fn ($q) => $q->where('b.branch_id', $this->branchId))
            ->selectRaw("to_char(b.bill_date, 'YYYY-MM') AS m, SUM(b.net_minor) AS s")->groupBy('m')->pluck('s', 'm');
        $out = [];
        for ($d = $start->copy(); $d->lte($end); $d->addMonth()) {
            $out[] = ['month' => $d->format('M Y'), 'spend' => (int) ($rows[$d->format('Y-m')] ?? 0)];
        }

        return $out;
    }

    /**
     * How the spend was bought: on an approved order (and of that, under a contract), received without an order, or
     * billed with no order at all (rent, utilities… or buying that skipped approval).
     *
     * @return array{on_order: int, on_contract: int, received_no_order: int, no_order: int, on_order_pct: float, on_contract_pct: float}
     */
    public function compliance(): array
    {
        $l = $this->lines();
        $total = max(1, (int) $l->sum('net_minor'));
        $onOrder = (int) $l->whereNotNull('order_line_id')->sum('net_minor');
        $contract = (int) $l->whereNotNull('agreement_line_id')->sum('net_minor');

        return ['on_order' => $onOrder, 'on_contract' => $contract,
            'received_no_order' => (int) $l->whereNull('order_line_id')->whereNotNull('goods_receipt_line_id')->sum('net_minor'),
            'no_order' => (int) $l->whereNull('order_line_id')->whereNull('goods_receipt_line_id')->sum('net_minor'),
            'on_order_pct' => round(100 * $onOrder / $total, 1), 'on_contract_pct' => round(100 * $contract / $total, 1)];
    }

    /** Bills against order prices: paid above (+) or below (−) what was ordered, and bills that needed approval for it. */
    public function priceVariance(): array
    {
        $b = $this->bills();

        return ['variance' => (int) (clone $b)->sum('b.variance_minor'), 'above' => (int) (clone $b)->where('b.variance_minor', '>', 0)->sum('b.variance_minor'),
            'bills_above' => (clone $b)->where('b.variance_minor', '>', 0)->count(), 'held' => (clone $b)->whereNotNull('b.approved_by_name')->count()];
    }

    /** What requests for quotation awarded in the period saved: each awarded line against the average of the other prices quoted. */
    public function rfqSavings(): array
    {
        $rfqs = DB::table('rfqs')->where('tenant_id', $this->tenantId)->where('status', 'awarded')->whereBetween('awarded_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])
            ->when($this->branchId, fn ($q) => $q->where('branch_id', $this->branchId))->pluck('id');
        $quotes = DB::table('rfq_quote_lines as q')->join('rfq_suppliers as s', 's.id', '=', 'q.rfq_supplier_id')->whereIn('s.rfq_id', $rfqs)
            ->get(['q.rfq_line_id', 'q.unit_cost_minor', 'q.qty', 'q.awarded'])->groupBy('rfq_line_id');
        $saved = 0;
        $awardedValue = 0;
        foreach ($quotes as $lineQuotes) {
            foreach ($lineQuotes->where('awarded', true) as $won) {
                $value = Qty::divRound(Qty::toMilli((string) $won->qty) * (int) $won->unit_cost_minor, 1000);
                $awardedValue += $value;
                $others = $lineQuotes->where('awarded', false)->pluck('unit_cost_minor');
                if ($others->isNotEmpty()) {
                    $saved += Qty::divRound(Qty::toMilli((string) $won->qty) * (int) round($others->avg() - $won->unit_cost_minor), 1000);
                }
            }
        }
        $invited = DB::table('rfq_suppliers')->whereIn('rfq_id', $rfqs)->count();
        $quoted = DB::table('rfq_suppliers')->whereIn('rfq_id', $rfqs)->where('status', 'quoted')->count();

        return ['rfqs' => $rfqs->count(), 'awarded' => $awardedValue, 'saved' => $saved, 'quotes_per_rfq' => $rfqs->isEmpty() ? null : round($quoted / $rfqs->count(), 1),
            'response_pct' => $invited ? round(100 * $quoted / $invited, 1) : null];
    }
}
