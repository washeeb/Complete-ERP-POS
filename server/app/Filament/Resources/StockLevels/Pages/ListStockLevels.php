<?php

namespace App\Filament\Resources\StockLevels\Pages;

use App\Domain\Inventory\InventoryService;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Support\Erp;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ListStockLevels extends ListRecords
{
    protected static string $resource = StockLevelResource::class;

    protected static ?string $title = 'Stock on hand';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjust')->label('Opening stock / adjustment')->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->visible(fn () => Erp::canAnywhere('stock.adjust'))
                ->modalDescription('Every change is recorded in the stock ledger with your name and reason; nothing is overwritten.')
                ->schema([
                    Radio::make('type')->options(['opening' => 'Opening balance (first time this product is counted here)', 'adjustment' => 'Adjustment'])
                        ->default('opening')->required()->live(),
                    Select::make('warehouse_id')->label('Warehouse')->required()
                        ->options(fn () => Warehouse::when(StockLevelResource::warehouseIds('stock.adjust') !== null,
                            fn ($q) => $q->whereIn('id', StockLevelResource::warehouseIds('stock.adjust')))
                            ->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])),
                    Select::make('product_id')->label('Product')->required()->live()->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Product::where('track_stock', true)
                            ->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"))
                            ->limit(30)->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"]))
                        ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name),
                    Select::make('product_uom_id')->label('Unit')->required()
                        ->options(fn (Get $get) => ProductUom::with('uom')->where('product_id', $get('product_id'))->get()
                            ->mapWithKeys(fn ($u) => [$u->id => $u->uom->code.($u->is_base ? '' : ' (× '.rtrim(rtrim((string) $u->factor, '0'), '.').')')])),
                    TextInput::make('qty')->label(fn (Get $get) => $get('type') === 'adjustment' ? 'Change (use minus to remove, e.g. -3)' : 'Quantity counted')
                        ->required(),
                    Select::make('reason')->options(InventoryService::ADJUSTMENT_REASONS)->required(fn (Get $get) => $get('type') === 'adjustment')
                        ->visible(fn (Get $get) => $get('type') === 'adjustment'),
                    TextInput::make('note')->maxLength(200)->visible(fn (Get $get) => $get('type') === 'adjustment'),
                    TextInput::make('unit_cost')->label('Unit cost (optional)')->numeric()->minValue(0)
                        ->helperText('Used for stock value from Phase 7. Leave blank if unknown.'),
                ])
                ->action(function (array $data) {
                    Erp::run(fn ($actor) => app(InventoryService::class)->adjust(
                        $actor, Warehouse::findOrFail($data['warehouse_id']), Product::findOrFail($data['product_id']),
                        ProductUom::findOrFail($data['product_uom_id']), (string) $data['qty'], $data['type'], $data['reason'] ?? '',
                        $data['note'] ?? null, isset($data['unit_cost']) ? (string) $data['unit_cost'] : null,
                    ), 'Stock updated');
                }),
        ];
    }
}
