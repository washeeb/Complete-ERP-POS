<?php

namespace App\Filament\Resources\Boms\Pages;

use App\Filament\Resources\Boms\BomResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBoms extends ListRecords
{
    protected static string $resource = BomResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New bill of materials')];
    }
}
