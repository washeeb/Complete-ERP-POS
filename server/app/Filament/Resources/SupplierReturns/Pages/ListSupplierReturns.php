<?php

namespace App\Filament\Resources\SupplierReturns\Pages;

use App\Filament\Pages\ExpiryReturns;
use App\Filament\Resources\SupplierReturns\SupplierReturnResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListSupplierReturns extends ListRecords
{
    protected static string $resource = SupplierReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('expiry')->label('Choose stock to return')->icon(Heroicon::OutlinedArchiveBoxXMark)
                ->visible(fn () => ExpiryReturns::canAccess())->url(ExpiryReturns::getUrl()),
        ];
    }
}
