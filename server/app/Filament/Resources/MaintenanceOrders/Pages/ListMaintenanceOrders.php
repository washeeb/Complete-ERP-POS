<?php

namespace App\Filament\Resources\MaintenanceOrders\Pages;

use App\Filament\Resources\MaintenanceOrders\MaintenanceOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceOrders extends ListRecords
{
    protected static string $resource = MaintenanceOrderResource::class;

    public function getSubheading(): ?string
    {
        return 'Servicing that is due (opened by the plans), breakdowns and customer visits. Log time and parts on each; “Done” puts the equipment back to work.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New work order')];
    }
}
