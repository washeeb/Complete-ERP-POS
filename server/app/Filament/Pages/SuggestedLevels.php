<?php

namespace App\Filament\Pages;

use App\Domain\Purchasing\ReorderLevels;
use App\Domain\Purchasing\SmartReorderLevels;
use App\Filament\Resources\ReorderRules\ReorderRuleResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\PagedRecords;
use App\Models\Warehouse;
use App\Support\Qty;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
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
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** Minimum and maximum worked out from sales, waiting to be accepted (Settings → Stock → Smart reorder levels). */
class SuggestedLevels extends Page implements HasTable
{
    use InteractsWithTable;
    use PagedRecords;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Suggested levels';

    protected static ?string $title = 'Suggested reorder levels';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return (Erp::canAnywhere('warehouse.manage') || Erp::canAnywhere('stock.levels')) && app(Settings::class)->get('stock.smart_levels', Erp::user()?->tenant_id) !== 'off';
    }

    public function getSubheading(): ?string
    {
        return 'Worked out every Monday from the last 12 weeks of sales, the supplier\'s delivery time and a safety margin. Tick the ones you agree with and accept: '
            .'they replace the default (or the product\'s own figure) in that store. Only changes of a fifth or more are listed.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recalculate')->label('Work them out now')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->action(function () {
                    $n = 0;
                    foreach (Warehouse::whereIn('id', array_keys(Fields::warehouses(['warehouse.manage', 'stock.levels'])))->get() as $w) {
                        $n += app(SmartReorderLevels::class)->recalculate($w)['suggested'];
                    }
                    Notification::make()->success()->title("{$n} suggestion(s)")->send();
                }),
            Action::make('levels')->label('Reorder levels')->color('gray')->url(ReorderRuleResource::getUrl('index')),
        ];
    }

    /** Every suggestion for the chosen store (shown a page at a time by PagedRecords). */
    protected function allRows(?array $filters, ?string $search): Collection
    {
        $warehouses = array_keys(Fields::warehouses(['warehouse.manage', 'stock.levels']));
        $wh = $filters['warehouse_id']['value'] ?? null;
        $rows = DB::table('reorder_level_suggestions as s')->join('products as p', 'p.id', '=', 's.product_id')->join('warehouses as w', 'w.id', '=', 's.warehouse_id')
            ->whereIn('s.warehouse_id', $wh && in_array($wh, $warehouses, true) ? [$wh] : $warehouses)->orderBy('p.name')
            ->get(['s.id', 's.warehouse_id', 's.product_id', 's.min_qty', 's.max_qty', 's.avg_daily', 's.lead_days', 'p.sku', 'p.name', 'w.code as warehouse']);
        $current = [];
        foreach ($rows->groupBy('warehouse_id') as $whId => $list) {
            $current[$whId] = app(ReorderLevels::class)->forWarehouse(Warehouse::find($whId), $list->pluck('product_id')->all());
        }

        return $rows->mapWithKeys(fn ($r) => [$r->id => (array) $r + ['key' => $r->id, 'now' => $current[$r->warehouse_id][$r->product_id] ?? null]]);

    }

    public function table(Table $table): Table
    {
        return $this->paged($table)
            ->columns([
                TextColumn::make('name')->label('Product')->description(fn (array $record) => $record['sku']),
                TextColumn::make('warehouse')->label('Store'),
                TextColumn::make('avg_daily')->label('Sells a day')->formatStateUsing(fn ($state) => Fields::qty((string) $state)),
                TextColumn::make('lead_days')->label('Delivery (days)'),
                TextColumn::make('now')->label('Now (min / max)')->state(fn (array $record) => $record['now']
                    ? ReorderRuleResource::inBuyingUnit($record['product_id'], Qty::format($record['now']['min'])).' / '
                        .ReorderRuleResource::inBuyingUnit($record['product_id'], Qty::format($record['now']['max']))
                    : 'none')
                    ->description(fn (array $record) => $record['now'] ? ReorderLevels::SOURCES[$record['now']['source']] : null),
                TextColumn::make('min_qty')->label('Suggested min / max')->weight('bold')
                    ->formatStateUsing(fn ($state, array $record) => ReorderRuleResource::inBuyingUnit($record['product_id'], $state).' / '
                        .ReorderRuleResource::inBuyingUnit($record['product_id'], $record['max_qty'])),
            ])
            ->filters([SelectFilter::make('warehouse_id')->label('Store')->options(fn () => Fields::warehouses(['warehouse.manage', 'stock.levels']))])
            ->toolbarActions([
                BulkAction::make('accept')->label('Accept')->icon(Heroicon::OutlinedCheck)->color('success')
                    ->action(function (Collection $records) {
                        $n = Erp::run(fn ($actor) => app(SmartReorderLevels::class)->accept($actor, $records->pluck('id')->all()));
                        Notification::make()->success()->title("{$n} level(s) accepted")->send();
                    }),
            ])
            ->emptyStateHeading('No suggestions')
            ->emptyStateDescription('Nothing has sold enough to suggest a different level, or the levels in use already match. Suggestions are worked out every Monday.');
    }
}
