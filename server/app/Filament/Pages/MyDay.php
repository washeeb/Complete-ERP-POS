<?php

namespace App\Filament\Pages;

use App\Domain\Inventory\CycleCounts;
use App\Domain\Inventory\StockBatches;
use App\Domain\Purchasing\ReorderService;
use App\Filament\Resources\ReorderRules\ReorderRuleResource;
use App\Filament\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Support\Erp;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * "My day" for the people who look after stock (Stock Controller release 2): one page with what needs doing in a store —
 * today's cycle count, low stock, expiring batches, negative stock, products never counted, adjustments waiting for a
 * manager, and goods on the way. Each figure opens the page where it is dealt with.
 */
class MyDay extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'My day';

    protected static ?string $title = 'My day';

    protected static ?int $navigationSort = 0;

    protected static ?string $slug = 'stock/my-day';

    protected string $view = 'filament.pages.my-day';

    #[Url]
    public string $warehouse = '';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('stock.view');
    }

    /** @return array<string, string> the stores this person may see */
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
    }

    public function store(): ?Warehouse
    {
        return $this->warehouse !== '' ? Warehouse::find($this->warehouse) : null;
    }

    /** @return list<array{label: string, value: int, hint: string, url: ?string, tone: string}> */
    public function cards(): array
    {
        $w = $this->store();
        if (! $w) {
            return [];
        }
        $today = StockBatches::today();
        $expiring = fn (int $days) => DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('l.warehouse_id', $w->id)->where('l.on_hand', '>', 0)->where('b.expires_on', '>=', $today)
            ->where('b.expires_on', '<', now()->addDays($days)->toDateString())->count();
        $expired = DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
            ->where('l.warehouse_id', $w->id)->where('l.on_hand', '>', 0)->where('b.expires_on', '<', $today)->count();
        $neverCounted = DB::table('stock_levels as sl')->join('products as p', 'p.id', '=', 'sl.product_id')->where('sl.warehouse_id', $w->id)
            ->where('sl.on_hand', '<>', 0)->where('p.track_stock', true)
            ->whereNotExists(fn ($q) => $q->from('stock_count_lines as l')->join('stock_counts as c', 'c.id', '=', 'l.stock_count_id')
                ->whereColumn('l.product_id', 'sl.product_id')->where('c.warehouse_id', $w->id)->where('c.status', 'posted')->whereNotNull('l.counted_qty'))->count();
        $expiryPage = ExpiryReturns::canAccess() ? ExpiryReturns::getUrl() : (BatchesExpiry::canAccess() ? BatchesExpiry::getUrl() : null);
        $low = count(app(ReorderService::class)->suggestions($w));

        return [
            ['label' => 'Low stock', 'value' => $low, 'hint' => 'At or below the minimum (counting what is on order)',
                'url' => ReorderSuggestions::canAccess() ? ReorderSuggestions::getUrl(['tableFilters' => ['warehouse_id' => ['value' => $w->id]]]) : (ReorderRuleResource::canViewAny() ? ReorderRuleResource::getUrl() : null), 'tone' => $low ? 'warning' : 'ok'],
            ['label' => 'Expired, still in stock', 'value' => $expired, 'hint' => 'Take them off the shelf', 'url' => $expiryPage, 'tone' => $expired ? 'danger' : 'ok'],
            ['label' => 'Expiring in 30 days', 'value' => $expiring(30), 'hint' => 'Batches with stock', 'url' => $expiryPage, 'tone' => $expiring(30) ? 'warning' : 'ok'],
            ['label' => 'Expiring in 31–90 days', 'value' => max(0, $expiring(90) - $expiring(30)), 'hint' => 'Plan returns or markdowns', 'url' => $expiryPage, 'tone' => 'info'],
            ['label' => 'Negative stock', 'value' => DB::table('stock_levels')->where('warehouse_id', $w->id)->where('on_hand', '<', 0)->count(), 'hint' => 'Sold more than the system had: count them',
                'url' => StockLevelResource::canViewAny() ? StockLevelResource::getUrl() : null, 'tone' => 'danger'],
            ['label' => 'Never counted here', 'value' => $neverCounted, 'hint' => 'Products with stock, no count yet', 'url' => null, 'tone' => 'info'],
            ['label' => 'Adjustments waiting for a manager', 'value' => StockAdjustment::where('warehouse_id', $w->id)->where('status', 'submitted')->count(), 'hint' => 'Submitted, not yet posted',
                'url' => StockAdjustmentResource::canViewAny() ? StockAdjustmentResource::getUrl() : null, 'tone' => 'info'],
            ['label' => 'Transfers on the way here', 'value' => StockTransfer::where('to_warehouse_id', $w->id)->where('status', 'in_transit')->count(), 'hint' => 'Receive them when they arrive',
                'url' => StockTransferResource::canViewAny() ? StockTransferResource::getUrl() : null, 'tone' => 'info'],
            ['label' => 'Orders to receive', 'value' => PurchaseOrder::where('warehouse_id', $w->id)->whereIn('status', ['approved', 'partially_received'])->count(), 'hint' => 'Approved purchase orders', 'url' => null, 'tone' => 'info'],
        ];
    }

    /** Today's cycle count: the products due (or the count already started). */
    public function cycle(): array
    {
        $w = $this->store();
        $svc = app(CycleCounts::class);
        if (! $w || $svc->perDay($w->tenant_id) === 0) {
            return ['on' => false];
        }
        $started = $svc->today($w);

        return ['on' => true, 'started' => $started, 'url' => $started ? StockCountResource::getUrl('view', ['record' => $started]) : null,
            'due' => $started ? collect() : $svc->due($w, $svc->perDay($w->tenant_id)), 'per_day' => $svc->perDay($w->tenant_id),
            'wrong' => DB::table('products')->whereIn('id', $svc->oftenWrongIds($w))->orderBy('name')->limit(10)->pluck('name')];
    }

    public function startCycleAction(): Action
    {
        return Action::make('startCycle')->label("Start today's count")->icon(Heroicon::OutlinedClipboardDocumentCheck)
            ->visible(fn () => ($w = $this->store()) && Erp::can('stock.count', $w))
            ->requiresConfirmation()->modalDescription('A blind count of the products listed: count them on the shelf (or with the handheld), then submit. A manager posts it.')
            ->action(function () {
                $count = Erp::run(fn ($actor) => app(CycleCounts::class)->start($actor, $this->store()));
                Notification::make()->success()->title("{$count->number} started")->body($count->lines()->count().' product(s) to count.')->send();
                $this->redirect(StockCountResource::getUrl('view', ['record' => $count]));
            });
    }
}
