<?php

namespace App\Filament\Resources\Rfqs\Pages;

use App\Filament\Resources\Rfqs\RfqResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRfqs extends ListRecords
{
    protected static string $resource = RfqResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Ask for quotes')];
    }
}
