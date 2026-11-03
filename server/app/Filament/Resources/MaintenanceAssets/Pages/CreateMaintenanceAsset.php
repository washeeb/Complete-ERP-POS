<?php

namespace App\Filament\Resources\MaintenanceAssets\Pages;

use App\Domain\Service\Maintenance;
use App\Filament\Resources\MaintenanceAssets\MaintenanceAssetResource;
use App\Filament\Support\Erp;
use App\Models\MaintenanceAsset;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMaintenanceAsset extends CreateRecord
{
    protected static string $resource = MaintenanceAssetResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(Maintenance::class)->saveAsset($actor, new MaintenanceAsset, $data));
    }

    protected function getRedirectUrl(): string
    {
        return MaintenanceAssetResource::getUrl('edit', ['record' => $this->record]);
    }
}
