<?php

namespace App\Filament\Resources\MaintenanceOrders\Pages;

use App\Domain\Service\Maintenance;
use App\Filament\Resources\MaintenanceOrders\MaintenanceOrderResource;
use App\Filament\Support\Erp;
use App\Models\MaintenanceAsset;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMaintenanceOrder extends CreateRecord
{
    protected static string $resource = MaintenanceOrderResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(Maintenance::class)->open($actor, MaintenanceAsset::findOrFail($data['asset_id']), $data + ['down' => (bool) ($data['down'] ?? false)]));
    }

    protected function getRedirectUrl(): string
    {
        return MaintenanceOrderResource::getUrl('view', ['record' => $this->record]);
    }
}
