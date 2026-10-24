<?php

namespace App\Filament\Resources\WhatsappOrders\Pages;

use App\Filament\Resources\WhatsappOrders\WhatsappOrderResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWhatsappOrder extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = WhatsappOrderResource::class;

    protected function getHeaderActions(): array
    {
        return WhatsappOrderResource::orderActions();
    }
}
