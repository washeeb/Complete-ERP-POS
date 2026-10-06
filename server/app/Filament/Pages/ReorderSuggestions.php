<?php

namespace App\Filament\Pages;

use App\Domain\Purchasing\ReorderService;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use UnitEnum;

/** What to order now (min/max), and turning it into draft purchase orders — one per supplier. */
class ReorderSuggestions extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'What to order';

    protected static ?string $title = 'What to order';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return Erp::canAnywhere('purchasing.manage');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (?array $filters) {
                $wh = Warehouse::find($filters['warehouse_id']['value'] ?? null) ?? Warehouse::whereIn('id', array_keys(Fields::warehouses('purchasing.manage')))->orderBy('code')->first();

                return collect($wh ? app(ReorderService::class)->suggestions($wh) : [])->keyBy('product_id')
                    ->map(fn ($s) => $s + ['warehouse_id' => $wh->id, 'key' => $s['product_id']]);
            })
            ->columns([
                TextColumn::make('name')->label('Product')->description(fn (array $record) => $record['sku']),
                TextColumn::make('on_hand')->label('On hand')->formatStateUsing(fn ($state) => Fields::qty(Qty::format($state))),
                TextColumn::make('on_order')->label('On order')->formatStateUsing(fn ($state) => Fields::qty(Qty::format($state))),
                TextColumn::make('in_transit')->label('In transit')->formatStateUsing(fn ($state) => Fields::qty(Qty::format($state))),
                TextColumn::make('min')->label('Min / max')->formatStateUsing(fn ($state, array $record) => Fields::qty(Qty::format($state)).' / '.Fields::qty(Qty::format($record['max']))),
                TextColumn::make('supplier')->label('Supplier')->placeholder('none set up')->color(fn ($state) => $state ? null : 'danger'),
                TextColumn::make('suggest_qty')->label('Order')->weight('bold')
                    ->formatStateUsing(fn ($state, array $record) => Fields::qty(Qty::format($state), $record['uom_code'])),
                TextColumn::make('unit_cost_minor')->label('Last cost')->placeholder('—')
                    ->formatStateUsing(fn ($state) => Money::display((int) $state, Tenant::find(Erp::user()->tenant_id)->currency_code)),
            ])
            ->filters([
                SelectFilter::make('warehouse_id')->label('Warehouse')->options(fn () => Fields::warehouses('purchasing.manage')),
            ])
            ->toolbarActions([
                BulkAction::make('order')->label('Create draft purchase orders')->icon(Heroicon::OutlinedDocumentPlus)
                    ->action(function (Collection $records) {
                        $first = $records->first();
                        $pos = Erp::run(fn ($actor) => app(ReorderService::class)->createDrafts($actor, Warehouse::findOrFail($first['warehouse_id']), $records->pluck('product_id')->all()));
                        Notification::make()->success()->title(count($pos).' draft purchase order(s) created')
                            ->body(collect($pos)->pluck('number')->implode(', '))->send();
                        $this->redirect(PurchaseOrderResource::getUrl('index'));
                    }),
            ])
            ->emptyStateHeading('Nothing to order')
            ->emptyStateDescription('Everything with a reorder level is above its minimum (counting what is already on order or in transit).');
    }
}
