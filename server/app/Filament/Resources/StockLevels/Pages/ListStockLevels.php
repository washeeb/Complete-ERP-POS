<?php

namespace App\Filament\Resources\StockLevels\Pages;

use App\Domain\Imports\Importers\StockRows;
use App\Domain\Inventory\InventoryService;
use App\Filament\Pages\ImportData;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Warehouse;
use App\Support\Exports\Exporter;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
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
            Action::make('stockSheet')->label('Stock sheet (before a count)')->icon(Heroicon::OutlinedClipboardDocumentList)->color('gray')
                ->modalDescription('The current stock standing of a warehouse, to print and count on paper or to fill in Excel. Leave out the system quantities for a blind count.')
                ->schema([
                    Select::make('warehouse_id')->label('Warehouse')->required()->options(fn () => Fields::warehouses('stock.view')),
                    Select::make('category_id')->label('Category')->placeholder('All products')->options(fn () => Category::orderBy('name')->pluck('name', 'id')),
                    Radio::make('show_qty')->label('System quantities')->options(['1' => 'Show them', '0' => 'Leave them out (blind count)'])->default('1'),
                    Radio::make('format')->options(['pdf' => 'PDF (to print)', 'csv' => 'CSV (to fill in Excel)'])->default('pdf'),
                    Radio::make('zero')->label('Products with no stock')->options(['1' => 'Include them', '0' => 'Leave them out'])->default('1'),
                ])
                ->action(fn (array $data) => $this->stockSheet($data)),
            Action::make('updateFromCsv')->label('Update stock from CSV')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->visible(fn () => Erp::canAnywhere('stock.adjust'))->url(ImportData::getUrl(['type' => 'stock_adjustment'])),
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
                        ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} · {$p->name}" : null),
                    Select::make('product_uom_id')->label('Unit')->required()
                        ->options(fn (Get $get) => ProductUom::with('uom')->where('product_id', $get('product_id'))->get()
                            ->mapWithKeys(fn ($u) => [$u->id => $u->uom->code.($u->is_base ? '' : ' (× '.rtrim(rtrim((string) $u->factor, '0'), '.').')')])),
                    TextInput::make('qty')->label(fn (Get $get) => $get('type') === 'adjustment' ? 'Change (use minus to remove, e.g. -3)' : 'Quantity counted')
                        ->required(),
                    Select::make('reason')->options(InventoryService::ADJUSTMENT_REASONS)->required(fn (Get $get) => $get('type') === 'adjustment')
                        ->visible(fn (Get $get) => $get('type') === 'adjustment'),
                    TextInput::make('note')->maxLength(200)->visible(fn (Get $get) => $get('type') === 'adjustment'),
                    TextInput::make('batch_no')->label('Batch no.')->maxLength(60)->visible(fn (Get $get) => (bool) Product::find($get('product_id'))?->track_batches)
                        ->helperText('Batch-tracked product. Removing stock without a batch takes the earliest expiry first.'),
                    DatePicker::make('expires_on')->label('Expiry')->visible(fn (Get $get) => (bool) Product::find($get('product_id'))?->track_batches),
                    TextInput::make('unit_cost')->label('Unit cost (optional)')->numeric()->minValue(0)
                        ->helperText('What one unit cost you. It values your stock and works out profit on sales. Leave blank if unknown.'),
                ])
                ->action(function (array $data) {
                    Erp::run(fn ($actor) => app(InventoryService::class)->adjust(
                        $actor, Warehouse::findOrFail($data['warehouse_id']), Product::findOrFail($data['product_id']),
                        ProductUom::findOrFail($data['product_uom_id']), (string) $data['qty'], $data['type'], $data['reason'] ?? '',
                        $data['note'] ?? null, isset($data['unit_cost']) ? (string) $data['unit_cost'] : null, $data['batch_no'] ?? null, $data['expires_on'] ?? null,
                    ), 'Stock updated');
                }),
        ];
    }

    /** Stock sheet: SKU, barcode, product, category, unit, system quantity (optional), and blank Counted / Difference columns. */
    private function stockSheet(array $data)
    {
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $show = (bool) $data['show_qty'];
        $rows = [];
        foreach (StockRows::standing($warehouse, $data['category_id'] ?? null, (bool) $data['zero']) as $s) {
            $p = $s['product'];
            $rows[] = [$p->sku, $s['barcode'], $p->name, $p->category, $p->unit, $show ? $s['on_hand'] : '', '', ''];
        }
        $sheet = ['title' => "Stock sheet {$warehouse->code}", 'headings' => ['SKU', 'Barcode', 'Product', 'Category', 'Unit', $show ? 'System qty' : 'System qty (hidden)', 'Counted', 'Difference'],
            'rows' => $rows, 'note' => "{$warehouse->code} · {$warehouse->name} · stock standing at ".now()->format('j M Y H:i').' · Counted by: ______________  Checked by: ______________', 'numeric' => [5, 6, 7]];
        $x = app(Exporter::class);

        return Erp::run(fn ($actor) => $data['format'] === 'csv' ? $x->csv($actor, $sheet['title'], [$sheet], [$warehouse->code]) : $x->pdf($actor, $sheet['title'], [$sheet], [$warehouse->code], 'portrait'));
    }
}
