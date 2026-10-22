<?php

namespace App\Filament\Resources\FixedAssets\Pages;

use App\Filament\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFixedAsset extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [FixedAssetResource::disposeAction()];
    }
}
