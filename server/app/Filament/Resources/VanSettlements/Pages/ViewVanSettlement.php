<?php

namespace App\Filament\Resources\VanSettlements\Pages;

use App\Filament\Resources\VanSettlements\VanSettlementResource;
use Filament\Resources\Pages\ViewRecord;

class ViewVanSettlement extends ViewRecord
{
    protected static string $resource = VanSettlementResource::class;

    protected function getHeaderActions(): array
    {
        return VanSettlementResource::settlementActions();
    }
}
