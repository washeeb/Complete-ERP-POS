<?php

namespace App\Filament\Resources\PurchaseAgreements\Pages;

use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseAgreements extends ListRecords
{
    protected static string $resource = PurchaseAgreementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New agreement')];
    }
}
