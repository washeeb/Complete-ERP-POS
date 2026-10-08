<?php

namespace App\Filament\Resources\Styles\Pages;

use App\Domain\Fashion\StyleService;
use App\Filament\Resources\Styles\StyleResource;
use App\Filament\Support\Erp;
use App\Models\Style;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStyles extends ListRecords
{
    protected static string $resource = StyleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New style')->using(fn (array $data) => Erp::run(fn ($actor) => app(StyleService::class)->save($actor, new Style, $data)))];
    }
}
