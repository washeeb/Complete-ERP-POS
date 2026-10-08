<?php

namespace App\Filament\Resources\Boms\Pages;

use App\Domain\Manufacturing\BomService;
use App\Filament\Resources\Boms\BomResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBom extends EditRecord
{
    protected static string $resource = BomResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return BomResource::toFormData($this->record) + $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(BomService::class)->save($actor, $record, $data));
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getRedirectUrl(): string
    {
        return BomResource::getUrl('view', ['record' => $this->record]);
    }
}
