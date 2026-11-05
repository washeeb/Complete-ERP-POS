<?php

namespace App\Filament\Resources\DirectPurchases\Pages;

use App\Filament\Resources\DirectPurchases\DirectPurchaseResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDirectPurchase extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = DirectPurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [...DirectPurchaseResource::documentActions(), EditAction::make()];
    }
}
