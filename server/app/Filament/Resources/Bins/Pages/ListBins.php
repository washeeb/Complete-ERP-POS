<?php

namespace App\Filament\Resources\Bins\Pages;

use App\Domain\Inventory\BinService;
use App\Filament\Resources\Bins\BinResource;
use App\Filament\Support\Erp;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

class ListBins extends ListRecords
{
    protected static string $resource = BinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('toggle')->label('Bins on/off')->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->visible(fn () => Erp::canAnywhere('warehouse.manage'))
                ->modalDescription('With bins on, every receipt goes to the receiving bin first and is put away from there; existing stock is placed in the receiving bin. Bins can be switched off only when they are all empty.')
                ->schema([
                    Select::make('warehouse_id')->label('Warehouse')->required()->live()
                        ->options(fn () => Warehouse::orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}".($w->use_bins ? ' (bins on)' : '')]))
                        ->afterStateUpdated(fn ($state, $set) => $set('use_bins', (bool) Warehouse::find($state)?->use_bins)),
                    Toggle::make('use_bins')->label('Use bins in this warehouse')->visible(fn (Get $get) => (bool) $get('warehouse_id')),
                ])
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(BinService::class)->setUseBins($actor, Warehouse::findOrFail($data['warehouse_id']), (bool) $data['use_bins']), 'Saved')),
        ];
    }
}
