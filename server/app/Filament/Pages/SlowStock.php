<?php

namespace App\Filament\Pages;

use App\Domain\Inventory\TransferService;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Support\Erp;
use App\Models\ProductUom;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Slow and dead stock (Stock Controller release 4): what has not sold in a store for 60, 90 or 180 days, its value at
 * cost, and what to do with it — move it to the store where it sells (a draft transfer in one click), ask the supplier to
 * take it back (where they do), or mark it down. Money tied up on the shelf is money not available for what sells.
 */
class SlowStock extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Slow & dead stock';

    protected static ?string $title = 'Slow and dead stock';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'stock/slow-stock';

    protected string $view = 'filament.pages.slow-stock';

    public const PERIODS = [60 => '60 days', 90 => '90 days', 180 => '180 days'];

    #[Url]
    public string $warehouse = '';

    #[Url]
    public int $days = 90;

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('stock.view');
    }

    /** @return array<string, string> */
    public function warehouses(): array
    {
        $ids = Erp::branchIds('stock.view');

        return Warehouse::when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])->all();
    }

    public function mount(): void
    {
        $this->updatedWarehouse();
    }

    public function updatedWarehouse(): void
    {
        $mine = $this->warehouses();
        $this->warehouse = array_key_exists($this->warehouse, $mine) ? $this->warehouse : (string) array_key_first($mine);
        $this->days = array_key_exists($this->days, self::PERIODS) ? $this->days : 90;
    }

    public function updatedDays(): void
    {
        $this->updatedWarehouse();
    }

    /**
     * @return Collection<int, object{product_id: string, sku: string, name: string, on_hand: string, value: int, last_sold: ?string, idle_days: int,
     *   move_to: ?object, supplier: ?string}>
     */
    public function rows(): Collection
    {
        if ($this->warehouse === '') {
            return collect();
        }
        $since = now()->subDays($this->days);
        $last = fn (array $types) => DB::table('stock_movements')->where('warehouse_id', $this->warehouse)->whereIn('type', $types)
            ->groupBy('product_id')->selectRaw('product_id, MAX(occurred_at) AS at')->pluck('at', 'product_id');
        $lastSold = $last(['sale']);
        $lastIn = $last(['receipt', 'opening', 'transfer_in', 'adjustment']);
        $rows = DB::table('stock_levels as sl')->join('products as p', 'p.id', '=', 'sl.product_id')
            ->where('sl.warehouse_id', $this->warehouse)->where('sl.on_hand', '>', 0)->where('p.track_stock', true)->where('p.is_active', true)
            ->get(['p.id', 'p.sku', 'p.name', 'sl.on_hand', 'sl.avg_cost_minor'])
            ->filter(function ($r) use ($lastSold, $lastIn, $since) {
                $sold = $lastSold[$r->id] ?? null;

                return $sold ? $sold < $since : (($lastIn[$r->id] ?? null) === null || $lastIn[$r->id] < $since); // never sold: idle since it came in
            });
        if ($rows->isEmpty()) {
            return collect();
        }
        $ids = $rows->pluck('id')->all();
        // Where the same products sell: units sold in the last 90 days in the other stores.
        $elsewhere = DB::table('stock_movements as m')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
            ->whereIn('m.product_id', $ids)->where('m.type', 'sale')->where('m.warehouse_id', '<>', $this->warehouse)->where('m.occurred_at', '>=', now()->subDays(90))
            ->groupBy('m.product_id', 'w.id', 'w.code', 'w.name')->selectRaw('m.product_id, w.id, w.code, w.name, -SUM(m.qty_base) AS sold')
            ->orderByDesc('sold')->get()->groupBy('product_id');
        $supplier = DB::table('goods_receipt_lines as l')->join('goods_receipts as g', 'g.id', '=', 'l.goods_receipt_id')->join('suppliers as s', 's.id', '=', 'g.supplier_id')
            ->whereIn('l.product_id', $ids)->where('g.warehouse_id', $this->warehouse)->whereNotNull('s.return_days_before_expiry')
            ->orderByDesc('g.created_at')->get(['l.product_id', 's.name'])->unique('product_id')->pluck('name', 'product_id');

        return $rows->map(fn ($r) => (object) [
            'product_id' => $r->id, 'sku' => $r->sku, 'name' => $r->name, 'on_hand' => (string) $r->on_hand,
            'value' => (int) round((float) $r->on_hand * (float) ($r->avg_cost_minor ?? 0)),
            'last_sold' => isset($lastSold[$r->id]) ? substr((string) $lastSold[$r->id], 0, 10) : null,
            'idle_days' => (int) now()->diffInDays($lastSold[$r->id] ?? $lastIn[$r->id] ?? now()->subYears(5), true),
            'move_to' => $elsewhere->get($r->id)?->first(), 'supplier' => $supplier->get($r->id),
        ])->sortByDesc('value')->values();
    }

    /** A draft transfer of the product's stock to the store where it sells; opened for checking and dispatching. */
    public function moveAction(): Action
    {
        return Action::make('move')->label('Move')->link()->icon(Heroicon::OutlinedArrowRight)
            ->visible(fn () => ($w = Warehouse::find($this->warehouse)) && Erp::can('stock.transfer', $w))
            ->requiresConfirmation()
            ->modalDescription(fn (array $arguments) => 'A draft transfer of all of it to '.(Warehouse::find($arguments['to'] ?? null)?->name ?? 'the other store').'. Check the quantity, then dispatch it.')
            ->action(function (array $arguments) {
                $unit = ProductUom::where('product_id', $arguments['product'])->where('is_base', true)->firstOrFail();
                $qty = (string) DB::table('stock_levels')->where('warehouse_id', $this->warehouse)->where('product_id', $arguments['product'])->value('on_hand');
                $t = Erp::run(fn ($actor) => app(TransferService::class)->saveDraft($actor, new StockTransfer, ['from_warehouse_id' => $this->warehouse,
                    'to_warehouse_id' => $arguments['to'], 'note' => 'Slow stock here, sells there', 'lines' => [['product_uom_id' => $unit->id, 'qty' => Qty::format(Qty::toMilli($qty))]]]));
                Notification::make()->success()->title("{$t->number} prepared")->body('Check it and dispatch it.')->send();
                $this->redirect(StockTransferResource::getUrl('view', ['record' => $t]));
            });
    }
}
