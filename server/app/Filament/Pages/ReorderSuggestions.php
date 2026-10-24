<?php

namespace App\Filament\Pages;

use App\Domain\Purchasing\ReorderLevels;
use App\Domain\Purchasing\ReorderService;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\PagedRecords;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Supplier;
use App\Models\SupplierProduct;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * What to order now (min/max), and turning it into draft purchase orders — one per supplier. The buyer can change a line's
 * supplier, unit, quantity and cost, and the product's levels for the store, before ordering (owner requests 2026-10-22/23):
 * each changeable value has a pencil beside it and its own small window; the changes stay on this page until the orders
 * are made, and "Undo my changes" in any of the windows puts the line back.
 */
class ReorderSuggestions extends Page implements HasTable
{
    use InteractsWithTable;
    use PagedRecords;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'What to order';

    protected static ?string $title = 'What to order';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.table-page';

    /** The buyer's changes: "warehouse id|product id" => [supplier_id, product_uom_id, qty]. */
    public array $changes = [];

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('purchasing.manage');
    }

    /** Every line to order for the chosen warehouse and supplier, with the buyer's changes (paged by PagedRecords). */
    protected function allRows(?array $filters, ?string $search): Collection
    {
        $wh = Warehouse::find($filters['warehouse_id']['value'] ?? null) ?? Warehouse::whereIn('id', array_keys(Fields::warehouses('purchasing.manage')))->orderBy('code')->first();
        $supplier = $filters['supplier_id']['value'] ?? null;

        return collect($wh ? app(ReorderService::class)->suggestions($wh) : [])
            ->map(fn ($s) => isset($this->changes["{$wh->id}|{$s['product_id']}"]) ? ReorderService::change($s, $this->changes["{$wh->id}|{$s['product_id']}"]) : $s)
            ->filter(fn ($s) => ! $supplier || ($supplier === 'none' ? $s['supplier_id'] === null : $s['supplier_id'] === $supplier))
            ->keyBy('product_id')->map(fn ($s) => $s + ['warehouse_id' => $wh->id, 'key' => $s['product_id'], 'changed' => false]);
    }

    public function table(Table $table): Table
    {
        $currency = Tenant::find(Erp::user()->tenant_id)->currency_code; // once, not once per row

        return $this->paged($table)
            ->columns([
                TextColumn::make('name')->label('Product')->description(fn (array $record) => $record['changed'] ? $record['sku'].' · changed by you' : $record['sku']),
                TextColumn::make('on_hand')->label('On hand')->formatStateUsing(fn ($state) => Fields::qty(Qty::format($state))),
                TextColumn::make('on_order')->label('On order')->formatStateUsing(fn ($state) => Fields::qty(Qty::format($state))),
                TextColumn::make('in_transit')->label('In transit')->formatStateUsing(fn ($state) => Fields::qty(Qty::format($state))),
                // What can be changed has a pencil beside it: click the value to change it there (owner request 2026-10-23).
                self::editable(TextColumn::make('min')->label('Min / max')->formatStateUsing(fn ($state, array $record) => Fields::qty(Qty::format($state)).' / '.Fields::qty(Qty::format($record['max'])))
                    ->description(fn (array $record) => ReorderLevels::SOURCES[$record['source']] ?? null), $this->levelsAction(), fn (array $record) => self::canSetLevels($record)),
                // Move first, buy the rest: another store of the business has more than its own maximum.
                TextColumn::make('move_qty')->label('From another store')->placeholder('—')->wrap()
                    ->formatStateUsing(fn ($state, array $record) => $state ? Fields::qty(Qty::format($state), $record['base_uom_code']).' from '.$record['move_from'] : null)
                    ->color('success'),
                self::editable(TextColumn::make('supplier')->label('Supplier')->placeholder('none set up — choose')->color(fn ($state) => $state ? null : 'danger'), $this->supplierAction()),
                self::editable(TextColumn::make('suggest_qty')->label('Order')->weight('bold')
                    ->formatStateUsing(fn ($state, array $record) => $state ? Fields::qty(Qty::format($state), $record['uom_code']) : 'nothing — moved instead'), $this->quantityAction()),
                self::editable(TextColumn::make('unit_cost_minor')->label('Cost')->placeholder('— enter')
                    ->formatStateUsing(fn ($state) => Money::display((int) $state, $currency)), $this->costAction()),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')->label('Warehouse')->options(fn () => Fields::warehouses('purchasing.manage')),
                SelectFilter::make('supplier_id')->label('Supplier')->query(fn ($query) => $query)
                    ->options(fn () => ['none' => 'No supplier set up'] + Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->toolbarActions([
                BulkAction::make('move')->label('Ask other stores to send')->icon(Heroicon::OutlinedArrowsRightLeft)->color('success')
                    ->modalDescription('One stock request to each store with stock to spare, for the ticked lines that show “From another store”. That store sends it as a transfer.')
                    ->requiresConfirmation()
                    ->action(function (Collection $records) {
                        $first = $records->first();
                        $requests = Erp::run(fn ($actor) => app(ReorderService::class)->requestMoves($actor, Warehouse::findOrFail($first['warehouse_id']), $records->pluck('product_id')->all()));
                        if ($requests) {
                            Notification::make()->success()->title(count($requests).' stock request(s) sent')->body(collect($requests)->pluck('number')->implode(', '))->send();
                            $this->pagedRowsCache = [];
                            $this->flushCachedTableRecords();
                        }
                    }),
                BulkAction::make('order')->label('Create draft purchase orders')->icon(Heroicon::OutlinedDocumentPlus)
                    ->action(function (Collection $records) {
                        $first = $records->first();
                        $changes = collect($this->changes)->filter(fn ($c, $k) => str_starts_with($k, $first['warehouse_id'].'|'))
                            ->mapWithKeys(fn ($c, $k) => [explode('|', $k)[1] => $c])->all();
                        $pos = Erp::run(fn ($actor) => app(ReorderService::class)->createDrafts($actor, Warehouse::findOrFail($first['warehouse_id']), $records->pluck('product_id')->all(), $changes));
                        $this->changes = [];
                        Notification::make()->success()->title(count($pos).' draft purchase order(s) created')
                            ->body(collect($pos)->pluck('number')->implode(', '))->send();
                        $this->redirect(PurchaseOrderResource::getUrl('index'));
                    }),
            ])
            ->emptyStateHeading('Nothing to order')
            ->emptyStateDescription('Everything is above its minimum (counting what is already on order or in transit). Products without their own figure use the company default from Settings → Stock.');
    }

    /** A value with a pencil beside it; clicking it opens its own small window. */
    private static function editable(TextColumn $column, Action $action, ?\Closure $allowed = null): TextColumn
    {
        return $column->icon(fn (array $record) => ! $allowed || $allowed($record) ? Heroicon::OutlinedPencilSquare : null)->iconPosition(IconPosition::After)->iconColor('primary')
            ->action($allowed ? $action->disabled(fn (array $record) => ! $allowed($record)) : $action)->tooltip(fn (array $record) => ! $allowed || $allowed($record) ? 'Change' : null);
    }

    /** @var array<string, bool> per warehouse, this request (asked for every row) */
    private static array $levelsAllowed = [];

    private static function canSetLevels(array $record): bool
    {
        return self::$levelsAllowed[$record['warehouse_id']] ??= (function () use ($record) {
            $wh = Warehouse::find($record['warehouse_id']);

            return Erp::can('stock.levels', $wh) || Erp::can('warehouse.manage', $wh);
        })();
    }

    /** This line's change so far, with the new values on top (kept on the page until the orders are made). */
    private function remember(array $record, array $over): void
    {
        $key = "{$record['warehouse_id']}|{$record['product_id']}";
        $this->changes[$key] = $over + ($this->changes[$key] ?? []) + ['supplier_id' => $record['supplier_id'], 'product_uom_id' => $record['product_uom_id'],
            'qty' => Qty::format($record['suggest_qty']), 'cost' => null];
        $this->pagedRowsCache = [];
        $this->flushCachedTableRecords(); // the changed line shows at once
    }

    /** "Undo my changes" in each window, once the line was changed. */
    private function undo(): Action
    {
        return Action::make('undo')->label('Undo my changes')->color('gray')->icon(Heroicon::OutlinedArrowUturnLeft)->cancelParentActions()
            ->visible(fn (array $record) => $record['changed'])
            ->action(function (array $record) {
                unset($this->changes["{$record['warehouse_id']}|{$record['product_id']}"]);
                $this->pagedRowsCache = [];
                $this->flushCachedTableRecords(); // the changed line shows at once
            });
    }

    private function supplierAction(): Action
    {
        return Action::make('supplier')->modalHeading(fn (array $record) => 'Supplier — '.$record['name'])->modalWidth('md')
            ->fillForm(fn (array $record) => ['supplier_id' => $record['supplier_id'], 'product_uom_id' => $record['product_uom_id']])
            ->schema(fn (array $record) => [
                Select::make('supplier_id')->label('Supplier')->required()->searchable()->options(self::suppliersFor($record['product_id']))
                    ->live()->afterStateUpdated(function ($state, Set $set) use ($record) {
                        $unit = SupplierProduct::where('supplier_id', $state)->where('product_id', $record['product_id'])->orderByDesc('is_preferred')->value('product_uom_id');
                        if ($unit) {
                            $set('product_uom_id', $unit); // the unit this supplier sells it in
                        }
                    }),
                Select::make('product_uom_id')->label('Unit')->required()->options(self::units($record['product_id'])),
            ])
            ->extraModalFooterActions([$this->undo()])
            ->action(fn (array $record, array $data) => $this->remember($record, ['supplier_id' => $data['supplier_id'], 'product_uom_id' => $data['product_uom_id']]));
    }

    private function quantityAction(): Action
    {
        return Action::make('quantity')->modalHeading(fn (array $record) => 'Order quantity — '.$record['name'])->modalWidth('md')
            ->fillForm(fn (array $record) => ['product_uom_id' => $record['product_uom_id'], 'qty' => Qty::format($record['suggest_qty'])])
            ->schema(fn (array $record) => [
                Select::make('product_uom_id')->label('Unit')->required()->options(self::units($record['product_id'])),
                TextInput::make('qty')->label('Order quantity')->numeric()->minValue(0.001)->required()->autofocus(),
            ])
            ->extraModalFooterActions([$this->undo()])
            ->action(fn (array $record, array $data) => $this->remember($record, ['product_uom_id' => $data['product_uom_id'], 'qty' => (string) $data['qty']]));
    }

    private function costAction(): Action
    {
        return Action::make('cost')->modalHeading(fn (array $record) => 'Cost — '.$record['name'])->modalWidth('md')
            ->fillForm(fn (array $record) => ['cost' => $record['cost'] ?? null])
            ->schema([
                TextInput::make('cost')->label('Cost per unit (before tax)')->numeric()->minValue(0)->autofocus()
                    ->helperText('Leave empty to use this supplier’s last cost. Needed when they have none yet.'),
            ])
            ->extraModalFooterActions([$this->undo()])
            ->action(fn (array $record, array $data) => $this->remember($record, ['cost' => filled($data['cost'] ?? null) ? (string) $data['cost'] : null]));
    }

    private function levelsAction(): Action
    {
        return Action::make('levels')->modalHeading(fn (array $record) => 'Levels in this store — '.$record['name'])->modalWidth('md')
            ->modalDescription('In the base unit. Saved for the product now, so the next suggestion follows them.')
            ->fillForm(fn (array $record) => ['min' => Qty::format($record['min']), 'max' => Qty::format($record['max'])])
            ->schema([
                TextInput::make('min')->label('Reorder at (minimum)')->numeric()->minValue(0)->required()->autofocus(),
                TextInput::make('max')->label('Fill up to (maximum)')->numeric()->minValue(0.001)->required(),
            ])
            ->action(function (array $record, array $data) {
                Erp::run(fn ($actor) => app(ReorderService::class)->saveRule($actor, Warehouse::findOrFail($record['warehouse_id']), Product::findOrFail($record['product_id']),
                    (string) $data['min'], (string) $data['max']), 'Levels saved');
                $this->pagedRowsCache = [];
                $this->flushCachedTableRecords(); // the changed line shows at once
            });
    }

    private static function units(string $productId): array
    {
        return ProductUom::with('uom')->where('product_id', $productId)->get()->mapWithKeys(fn ($u) => [$u->id => Fields::unitLabel($u)])->all();
    }

    /** This product's suppliers first (with their last cost), then every other active supplier. */
    private static function suppliersFor(string $productId): array
    {
        $currency = Tenant::find(Erp::user()->tenant_id)->currency_code;
        $theirs = SupplierProduct::with('supplier')->where('product_id', $productId)->get()->filter(fn ($sp) => $sp->supplier?->is_active)
            ->mapWithKeys(fn ($sp) => [$sp->supplier_id => $sp->supplier->name.($sp->last_cost_minor !== null ? ' — last '.Money::display((int) $sp->last_cost_minor, $currency) : '')])->all();

        return $theirs + Supplier::where('is_active', true)->whereNotIn('id', array_keys($theirs))->orderBy('name')->pluck('name', 'id')->all();
    }
}
