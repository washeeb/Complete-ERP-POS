<?php

namespace App\Filament\Resources\Styles\Pages;

use App\Filament\Resources\Styles\StyleResource;
use Filament\Resources\Pages\ViewRecord;

class ViewStyle extends ViewRecord
{
    protected static string $resource = StyleResource::class;

    protected function getHeaderActions(): array
    {
        return StyleResource::styleActions();
    }
}
