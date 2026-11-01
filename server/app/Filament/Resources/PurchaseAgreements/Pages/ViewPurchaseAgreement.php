<?php

namespace App\Filament\Resources\PurchaseAgreements\Pages;

use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseAgreement extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = PurchaseAgreementResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make(), ...PurchaseAgreementResource::workflowActions()];
    }
}
