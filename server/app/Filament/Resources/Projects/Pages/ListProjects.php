<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    public function getSubheading(): ?string
    {
        return 'Each project collects its costs by itself — stock issued, bills and expenses charged to it, approved time — and shows what it earns.';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New project')];
    }
}
