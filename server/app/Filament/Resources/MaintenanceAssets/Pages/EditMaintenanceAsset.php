<?php

namespace App\Filament\Resources\MaintenanceAssets\Pages;

use App\Domain\Service\Maintenance;
use App\Filament\Resources\MaintenanceAssets\MaintenanceAssetResource;
use App\Filament\Support\Erp;
use App\Filament\Support\ServiceUi;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMaintenanceAsset extends EditRecord
{
    protected static string $resource = MaintenanceAssetResource::class;

    protected function getHeaderActions(): array
    {
        return MaintenanceAssetResource::assetActions();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(Maintenance::class)->saveAsset($actor, $record, $data));
    }

    protected function getFormActions(): array
    {
        return ServiceUi::can('maintenance.manage') ? parent::getFormActions() : [];
    }
}
