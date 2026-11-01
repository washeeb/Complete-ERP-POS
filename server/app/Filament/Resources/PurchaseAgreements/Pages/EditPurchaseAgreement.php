<?php

namespace App\Filament\Resources\PurchaseAgreements\Pages;

use App\Domain\Purchasing\AgreementService;
use App\Filament\Resources\PurchaseAgreements\PurchaseAgreementResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPurchaseAgreement extends EditRecord
{
    protected static string $resource = PurchaseAgreementResource::class;

    /** The uploaded file as the service stores it (kept when no new one is chosen). */
    public static function withFile(array $data): array
    {
        if (! empty($data['file'])) {
            $data['file_path'] = is_array($data['file']) ? array_values($data['file'])[0] : $data['file'];
        }
        unset($data['file']);

        return $data;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data + PurchaseAgreementResource::toFormData($this->record) + ['file' => $this->record->file_path];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(AgreementService::class)->save($actor, $record, self::withFile($data)));
    }

    protected function getRedirectUrl(): string
    {
        return PurchaseAgreementResource::getUrl('view', ['record' => $this->record]);
    }
}
