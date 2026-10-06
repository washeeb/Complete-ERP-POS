<?php

namespace App\Filament\Resources\FixedAssets\Pages;

use App\Domain\Assets\AssetService;
use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateFixedAsset extends CreateRecord
{
    protected static string $resource = FixedAssetResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(AssetService::class)->register($actor, [
            'name' => $data['name'], 'asset_class_id' => $data['asset_class_id'], 'branch_id' => $data['branch_id'], 'acquired_on' => $data['acquired_on'],
            'cost_minor' => FinanceUi::toMinor($data['cost']), 'residual_minor' => FinanceUi::toMinor($data['residual'] ?? 0), 'life_months' => $data['life_months'] ?? null,
            'opening_depreciation_minor' => FinanceUi::toMinor($data['opening_depreciation'] ?? 0), 'serial_no' => $data['serial_no'] ?? null, 'location' => $data['location'] ?? null,
        ]));
    }

    protected function getRedirectUrl(): string
    {
        return FixedAssetResource::getUrl('view', ['record' => $this->record]);
    }
}
