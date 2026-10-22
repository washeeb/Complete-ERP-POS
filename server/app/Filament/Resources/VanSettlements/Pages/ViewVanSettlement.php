<?php

namespace App\Filament\Resources\VanSettlements\Pages;

use App\Filament\Resources\VanSettlements\VanSettlementResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVanSettlement extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = VanSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return VanSettlementResource::settlementActions();
    }
}
