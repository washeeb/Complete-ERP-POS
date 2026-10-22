<?php

namespace App\Filament\Resources\Styles\Pages;

use App\Filament\Resources\Styles\StyleResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStyle extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = StyleResource::class;

    protected function getHeaderActions(): array
    {
        return StyleResource::styleActions();
    }
}
