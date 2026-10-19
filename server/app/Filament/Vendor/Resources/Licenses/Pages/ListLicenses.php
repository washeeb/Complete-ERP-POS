<?php

namespace App\Filament\Vendor\Resources\Licenses\Pages;

use App\Filament\Vendor\Resources\Licenses\LicenseResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListLicenses extends ListRecords
{
    protected static string $resource = LicenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label('Download (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')->url(route('vendor.licenses.csv')),
            Action::make('issue')->label('Issue a licence')->icon(Heroicon::OutlinedKey)->url(LicenseResource::getUrl('create')),
        ];
    }
}
