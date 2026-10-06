<?php

namespace App\Filament\Resources\StockCounts\Pages;

use App\Domain\Inventory\StockCountService;
use App\Filament\Resources\StockCounts\StockCountResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\Bin;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ListStockCounts extends ListRecords
{
    protected static string $resource = StockCountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')->label('Start a count')->icon(Heroicon::OutlinedPlay)
                ->visible(fn () => Erp::canAnywhere('stock.count'))
                ->schema([
                    Select::make('warehouse_id')->label('Warehouse')->required()->live()->options(fn () => Fields::warehouses('stock.count')),
                    Select::make('bin_id')->label('Only this bin (optional)')->options(fn (Get $get) => Fields::bins($get('warehouse_id')))
                        ->visible(fn (Get $get) => (bool) Warehouse::find($get('warehouse_id'))?->use_bins),
                    Toggle::make('blind')->label('Blind count (counters do not see expected quantities)')->default(true),
                    TextInput::make('note')->maxLength(255),
                ])
                ->action(function (array $data) {
                    $count = Erp::run(fn ($actor) => app(StockCountService::class)->start($actor, Warehouse::findOrFail($data['warehouse_id']),
                        isset($data['bin_id']) ? Bin::find($data['bin_id']) : null, (bool) $data['blind'], null, $data['note'] ?? null), 'Count started');
                    $this->redirect(StockCountResource::getUrl('view', ['record' => $count]));
                }),
        ];
    }
}
