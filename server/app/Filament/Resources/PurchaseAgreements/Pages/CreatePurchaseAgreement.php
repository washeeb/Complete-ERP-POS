<?php

namespace App\Filament\Resources\PurchaseAgreements\Pages;

use App\Domain\Purchasing\AgreementService;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use App\Filament\Support\Erp;
use App\Models\PurchaseAgreement;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePurchaseAgreement extends CreateRecord
{
    protected static string $resource = PurchaseAgreementResource::class;

    protected static ?string $title = 'New contract or blanket order';

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(AgreementService::class)->save($actor, new PurchaseAgreement, EditPurchaseAgreement::withFile($data)));
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseAgreementResource::getUrl('view', ['record' => $this->record]);
    }
}
