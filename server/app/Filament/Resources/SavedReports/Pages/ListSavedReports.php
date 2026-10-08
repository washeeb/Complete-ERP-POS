<?php

namespace App\Filament\Resources\SavedReports\Pages;

use App\Filament\Pages\ReportBuilder;
use App\Filament\Resources\SavedReports\SavedReportResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSavedReports extends ListRecords
{
    protected static string $resource = SavedReportResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('new')->label('New report')->icon(Heroicon::OutlinedPlus)->url(ReportBuilder::getUrl())];
    }
}
