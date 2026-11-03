<?php

namespace App\Filament\Resources\MaintenanceAssets\Pages;

use App\Filament\Resources\MaintenanceAssets\MaintenanceAssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceAssets extends ListRecords
{
    protected static string $resource = MaintenanceAssetResource::class;

    public function getSubheading(): ?string
    {
        return 'What you look after, with its servicing plans and work orders: what it costs to keep running and how long it was down.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New equipment')];
    }
}
