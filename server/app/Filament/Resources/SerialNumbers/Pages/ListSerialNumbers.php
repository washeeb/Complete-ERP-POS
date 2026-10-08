<?php

namespace App\Filament\Resources\SerialNumbers\Pages;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\SerialRegistry;
use App\Filament\Resources\SerialNumbers\SerialNumberResource;
use App\Filament\Support\Erp;
use App\Models\Product;
use App\Models\Warehouse;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Resources\Pages\ListRecords;

class ListSerialNumbers extends ListRecords
{
    protected static string $resource = SerialNumberResource::class;

    protected function getHeaderActions(): array
    {
        $gaps = count(SerialRegistry::check(Erp::user()?->tenant_id));

        return [
            Action::make('register')->label('Register numbers for stock on hand')->visible(fn () => Erp::canAnywhere('stock.adjust'))
                ->modalDescription('For units already in stock without a number (held before serial tracking was switched on, or found in a count).'
                    .($gaps ? " {$gaps} product/warehouse pair(s) do not match their stock now." : ''))
                ->schema([
                    Select::make('warehouse_id')->label('Warehouse')->required()->options(fn () => Warehouse::orderBy('code')->pluck('name', 'id')),
                    Select::make('product_id')->label('Product')->required()->searchable()->options(fn () => Product::where('track_serials', true)->orderBy('name')->pluck('name', 'id')),
                    TagsInput::make('serials')->label('Serial / IMEI numbers')->required(),
                ])
                ->action(fn (array $data) => Erp::run(fn ($actor) => app(InventoryService::class)->registerSerials($actor, Warehouse::findOrFail($data['warehouse_id']),
                    Product::findOrFail($data['product_id']), $data['serials']), 'Serial numbers registered')),
        ];
    }
}
