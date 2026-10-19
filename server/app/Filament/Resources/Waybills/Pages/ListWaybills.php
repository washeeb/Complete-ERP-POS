<?php

namespace App\Filament\Resources\Waybills\Pages;

use App\Filament\Resources\Waybills\WaybillResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWaybills extends ListRecords
{
    protected static string $resource = WaybillResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New waybill')];
    }
}
