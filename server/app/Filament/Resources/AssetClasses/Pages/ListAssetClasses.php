<?php

namespace App\Filament\Resources\AssetClasses\Pages;

use App\Domain\Assets\AssetService;
use App\Filament\Resources\AssetClasses\AssetClassResource;
use App\Filament\Support\Erp;
use App\Models\AssetClass;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAssetClasses extends ListRecords
{
    protected static string $resource = AssetClassResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => Erp::run(fn ($actor) => app(AssetService::class)->saveClass($actor, new AssetClass, $data)))];
    }
}
