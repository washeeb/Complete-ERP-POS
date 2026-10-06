<?php

namespace App\Filament\Resources\FixedAssets\Pages;

use App\Filament\Resources\AssetClasses\AssetClassResource;
use App\Filament\Resources\FixedAssets\FixedAssetResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListFixedAssets extends ListRecords
{
    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('classes')->label('Kinds of asset')->icon(Heroicon::OutlinedTag)->color('gray')->url(AssetClassResource::getUrl()),
            CreateAction::make()->label('Register an asset'),
        ];
    }
}
