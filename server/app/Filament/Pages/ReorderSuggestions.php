<?php

namespace App\Filament\Pages;

use App\Domain\Purchasing\ReorderLevels;
use App\Domain\Purchasing\ReorderService;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
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
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
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
 * supplier, unit and quantity, and the product's levels for the store, before ordering (owner request 2026-10-22); the
 * changes stay on this page until the orders are made.
 */
class ReorderSuggestions extends Page implements HasTable
{
    use InteractsWithTable;

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

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters) {
                $wh = Warehouse::find($filters['warehouse_id']['value'] ?? null) ?? Warehouse::whereIn('id', array_keys(Fields::warehouses('purchasing.manage')))->orderBy('code')->first();

                $supplier = $filters['supplier_id']['value'] ?? null;

                return collect($wh ? app(ReorderService::class)->suggestions($wh) : [])
                    ->map(fn ($s) => isset($this->changes["{$wh->id}|{$s['product_id']}"]) ? ReorderService::change($s, $this->changes["{$wh->id}|{$s['product_id']}"]) : $s)
                    ->filter(fn ($s) => ! $supplier || ($supplier === 'none' ? $s['supplier_id'] === null : $s['supplier_id'] === $supplier))
                    ->keyBy('product_id')->map(fn ($s) => $s + ['warehouse_id' => $wh->id, 'key' => $s['product_id'], 'changed' => false]);
            })
            ->columns([
                TextColumn::make('name')->label('Product')->description(fn (array $record) => $record['sku']),
                TextColumn::make('on_hand')->label('On hand')->formatStateUsing(fn ($state) => Fields::qty(Qty::format($state))),
                TextColumn::make('on_order')->label('On order')->formatStateUsing(fn ($state) => Fields::qty(Qty::format($state))),
                TextColumn::make('in_transit')->label('In transit')->formatStateUsing(fn ($state) => Fields::qty(Qty::format($state))),
                TextColumn::make('min')->label('Min / max')->formatStateUsing(fn ($state, array $record) => Fields::qty(Qty::format($state)).' / '.Fields::qty(Qty::format($record['max'])))
                    ->description(fn (array $record) => ReorderLevels::SOURCES[$record['source']] ?? null),
                TextColumn::make('supplier')->label('Supplier')->placeholder('none set up')->color(fn ($state) => $state ? null : 'danger')
                    ->description(fn (array $record) => $record['changed'] ? 'Changed by you' : null),
                TextColumn::make('suggest_qty')->label('Order')->weight('bold')
                    ->formatStateUsing(fn ($state, array $record) => Fields::qty(Qty::format($state), $record['uom_code'])),
                TextColumn::make('unit_cost_minor')->label('Last cost')->placeholder('—')
                    ->formatStateUsing(fn ($state) => Money::display((int) $state, Tenant::find(Erp::user()->tenant_id)->currency_code)),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')->label('Warehouse')->options(fn () => Fields::warehouses('purchasing.manage')),
                SelectFilter::make('supplier_id')->label('Supplier')->query(fn ($query) => $query)
                    ->options(fn () => ['none' => 'No supplier set up'] + Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('change')->label('Change')->icon(Heroicon::OutlinedPencilSquare)->modalHeading(fn (array $record) => $record['name'])
                    ->fillForm(fn (array $record) => ['supplier_id' => $record['supplier_id'], 'product_uom_id' => $record['product_uom_id'], 'qty' => Qty::format($record['suggest_qty']), 'cost' => $record['cost'] ?? null,
                        'min' => Qty::format($record['min']), 'max' => Qty::format($record['max'])])
                    ->schema(fn (array $record) => [
                        Select::make('supplier_id')->label('Supplier')->required()->searchable()->options(self::suppliersFor($record['product_id']))
                            ->live()->afterStateUpdated(function ($state, Set $set) use ($record) {
                                $unit = SupplierProduct::where('supplier_id', $state)->where('product_id', $record['product_id'])->orderByDesc('is_preferred')->value('product_uom_id');
                                if ($unit) {
                                    $set('product_uom_id', $unit);
                                }
                            }),
                        Select::make('product_uom_id')->label('Unit')->required()
                            ->options(ProductUom::with('uom')->where('product_id', $record['product_id'])->get()->mapWithKeys(fn ($u) => [$u->id => Fields::unitLabel($u)])),
                        TextInput::make('qty')->label('Order quantity')->numeric()->minValue(0.001)->required(),
                        TextInput::make('cost')->label('Cost per unit (before tax)')->numeric()->minValue(0)
                            ->helperText('Leave empty to use this supplier’s last cost. Needed when they have none yet.'),
                        Section::make('Levels in this store')->description('In the base unit. Saved for the product now, so next time\'s suggestion follows them.')
                            ->visible(fn () => Erp::can('stock.levels', $wh = Warehouse::find($record['warehouse_id'])) || Erp::can('warehouse.manage', $wh))
                            ->columns(2)->schema([
                                TextInput::make('min')->label('Reorder at (minimum)')->numeric()->minValue(0)->required(),
                                TextInput::make('max')->label('Fill up to (maximum)')->numeric()->minValue(0.001)->required(),
                            ]),
                    ])
                    ->action(function (array $record, array $data) {
                        $wh = Warehouse::findOrFail($record['warehouse_id']);
                        $this->changes["{$wh->id}|{$record['product_id']}"] = ['supplier_id' => $data['supplier_id'], 'product_uom_id' => $data['product_uom_id'], 'qty' => (string) $data['qty'],
                            'cost' => filled($data['cost'] ?? null) ? (string) $data['cost'] : null];
                        if (isset($data['min'], $data['max']) && (Qty::toMilli((string) $data['min']) !== $record['min'] || Qty::toMilli((string) $data['max']) !== $record['max'])) {
                            Erp::run(fn ($actor) => app(ReorderService::class)->saveRule($actor, $wh, Product::findOrFail($record['product_id']), (string) $data['min'], (string) $data['max']), 'Levels saved');
                        }
                        $this->flushCachedTableRecords();
                    }),
                Action::make('undo')->label('Undo change')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')
                    ->visible(fn (array $record) => $record['changed'])
                    ->action(function (array $record) {
                        unset($this->changes["{$record['warehouse_id']}|{$record['product_id']}"]);
                        $this->flushCachedTableRecords();
                    }),
            ])
            ->toolbarActions([
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

    /** This product's suppliers first (with their last cost), then every other active supplier. */
    private static function suppliersFor(string $productId): array
    {
        $currency = Tenant::find(Erp::user()->tenant_id)->currency_code;
        $theirs = SupplierProduct::with('supplier')->where('product_id', $productId)->get()->filter(fn ($sp) => $sp->supplier?->is_active)
            ->mapWithKeys(fn ($sp) => [$sp->supplier_id => $sp->supplier->name.($sp->last_cost_minor !== null ? ' — last '.Money::display((int) $sp->last_cost_minor, $currency) : '')])->all();

        return $theirs + Supplier::where('is_active', true)->whereNotIn('id', array_keys($theirs))->orderBy('name')->pluck('name', 'id')->all();
    }
}
