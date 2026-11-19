<?php

namespace App\Domain\Office;

use App\Domain\Inventory\TransferService;
use App\Domain\Support\Actor;
use App\Filament\Pages\ShrinkReport;
use App\Filament\Pages\SlowStock;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Models\ProductUom;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Qty;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stock reports in the new back office (release 7 of the rewrite). Shrink: losses at cost (adjustments and count
 * shortages) by reason, product, month, category and person, and the products lost again and again. Slow and dead
 * stock: what has not sold in a store, with the suggestion of the current page (move it where it sells, ask the
 * supplier to take it back, or mark it down) and Move, a draft transfer. The figures come from the current pages'
 * own queries (ShrinkReport, SlowStock), so both show the same.
 */
final class StockReportsScreen
{
    public function __construct(private readonly User $user) {}

    public static function canView(): bool
    {
        return ShrinkReport::canAccess();
    }

    private function m(int|float|string|null $v): string
    {
        return FinanceUi::money((int) $v);
    }

    /** @param  array<string, mixed>  $p  from, to, warehouse ('' = all the person may see) */
    public function shrink(array $p): array
    {
        $date = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        $page = new ShrinkReport;
        $page->from = $date($p['from'] ?? null) ?? now()->startOfMonth()->toDateString();
        $page->to = $date($p['to'] ?? null) ?? now()->toDateString();
        $page->warehouse = (string) ($p['warehouse'] ?? '');
        $page->updatedWarehouse();

        $reasons = $page->byReason();
        $months = $page->byMonth();
        $top = max(1, (int) $months->max('value'));
        $none = 'No losses recorded.';

        return [
            'from' => $page->from, 'to' => $page->to, 'warehouse' => $page->warehouse, 'warehouses' => PurchaseOrdersScreen::choices($page->warehouses()),
            'total' => $this->m($reasons->sum('value')), 'entries' => (int) $reasons->sum('lines'),
            'tables' => [
                ReportTables::table('By reason (at cost)', ['Reason', '#Entries', '#Value'], ReportTables::rows($reasons, fn ($x) => [$x->reason, (string) $x->lines, $this->m($x->value)]),
                    $reasons->isNotEmpty() ? ['Total', (string) $reasons->sum('lines'), $this->m($reasons->sum('value'))] : [], empty: $none),
                ReportTables::table('Top products', ['Product', 'SKU', '#Quantity', '#Value'],
                    ReportTables::rows($page->byProduct(), fn ($x) => [$x->name, $x->sku, Fields::qty((string) $x->qty), $this->m($x->value)]), empty: $none),
                ReportTables::table('Last six months', ['Month', '', '#Value'],
                    ReportTables::rows($months, fn ($x) => [$x->month, ['t' => '', 'bar' => (int) round(100 * $x->value / $top)], $this->m($x->value)]), empty: $none),
                ReportTables::table('By category', ['Category', '#Value'], ReportTables::rows($page->byCategory(), fn ($x) => [$x->category, $this->m($x->value)]), empty: $none),
                ReportTables::table('Recorded by', ['Person', '#Entries', '#Value'], ReportTables::rows($page->byPerson(), fn ($x) => [$x->person, (string) $x->lines, $this->m($x->value)]), empty: $none),
                ReportTables::table('Lost again and again (3 times or more)', ['Product', 'SKU', '#Times', '#Value'],
                    ReportTables::rows($page->repeated(), fn ($x) => [$x->name, $x->sku, (string) $x->times, $this->m($x->value)]),
                    hint: 'Look at where these are kept and who handles them.', empty: 'None in this period.'),
            ],
        ];
    }

    public function shrinkSheets(array $p): array
    {
        $d = $this->shrink($p);
        $where = collect($d['warehouses'])->firstWhere('id', $d['warehouse'])['label'] ?? 'All';

        return ['title' => 'Shrink and write-offs', 'sheets' => ReportTables::sheets($d['tables'], 'Shrink'), 'filters' => ["From: {$d['from']}", "To: {$d['to']}", "Warehouse: {$where}"]];
    }

    private function slowPage(array $p): SlowStock
    {
        $page = new SlowStock;
        $page->warehouse = (string) ($p['warehouse'] ?? '');
        $page->days = (int) ($p['days'] ?? 90);
        $page->updatedWarehouse();

        return $page;
    }

    /** @param  array<string, mixed>  $p  warehouse, days (60, 90 or 180) */
    public function slow(array $p): array
    {
        $page = $this->slowPage($p);
        $rows = $page->rows();
        $w = Warehouse::find($page->warehouse);

        return [
            'warehouse' => $page->warehouse, 'warehouses' => PurchaseOrdersScreen::choices($page->warehouses()), 'days' => $page->days,
            'periods' => array_map(fn ($d, $l) => ['id' => (string) $d, 'label' => $l], array_keys(SlowStock::PERIODS), SlowStock::PERIODS),
            'count' => $rows->count(), 'value' => $this->m($rows->sum('value')), 'can_move' => $w && Erp::can('stock.transfer', $w),
            'rows' => $rows->map(fn ($r) => [
                'id' => $r->product_id, 'sku' => $r->sku, 'name' => $r->name, 'on_hand' => Fields::qty($r->on_hand), 'value' => $this->m($r->value),
                'last_sold' => $r->last_sold ? Carbon::parse($r->last_sold)->format('j M Y') : null, 'idle_days' => $r->idle_days,
                'move_to' => $r->move_to ? ['id' => $r->move_to->id, 'code' => $r->move_to->code, 'name' => $r->move_to->name, 'sold' => Fields::qty((string) $r->move_to->sold)] : null,
                'supplier' => $r->supplier,
            ])->all(),
        ];
    }

    public function slowSheets(array $p): array
    {
        $d = $this->slow($p);
        $rows = array_map(fn ($r) => ['cells' => [$r['name'], $r['sku'], $r['on_hand'], $r['value'], ($r['last_sold'] ?? 'never')." ({$r['idle_days']} days)",
            $r['move_to'] ? "Sells at {$r['move_to']['code']} ({$r['move_to']['sold']} in 90 days): move it there"
                : ($r['supplier'] ? "Ask {$r['supplier']} to take it back" : 'Mark it down or put it in a bundle')]], $d['rows']);
        $where = collect($d['warehouses'])->firstWhere('id', $d['warehouse'])['label'] ?? '';

        return ['title' => 'Slow and dead stock', 'filters' => ["Store: {$where}", 'Not sold for: '.(SlowStock::PERIODS[$d['days']] ?? '')],
            'sheets' => ReportTables::sheets([ReportTables::table('Slow and dead stock', ['Product', 'SKU', '#On hand', '#Value', 'Last sold', 'Suggestion'], $rows)], 'Nothing slow')];
    }

    /** Move: a draft transfer of all of the product's stock here to the store where it sells, to check and dispatch. */
    public function move(Actor $actor, string $warehouseId, string $productId, string $to): StockTransfer
    {
        $unit = ProductUom::where('product_id', $productId)->where('is_base', true)->firstOrFail();
        $qty = (string) DB::table('stock_levels')->where('warehouse_id', $warehouseId)->where('product_id', $productId)->value('on_hand');

        return app(TransferService::class)->saveDraft($actor, new StockTransfer, ['from_warehouse_id' => $warehouseId, 'to_warehouse_id' => $to,
            'note' => 'Slow stock here, sells there', 'lines' => [['product_uom_id' => $unit->id, 'qty' => Qty::format(Qty::toMilli($qty))]]]);
    }
}
