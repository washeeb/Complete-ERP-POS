<?php

namespace App\Filament\Resources\Boms\Pages;

use App\Domain\Manufacturing\BomService;
use App\Filament\Resources\Boms\BomResource;
use App\Filament\Support\Erp;
use App\Models\Bom;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBom extends CreateRecord
{
    protected static string $resource = BomResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(BomService::class)->save($actor, new Bom, $data));
    }

    protected function getRedirectUrl(): string
    {
        return BomResource::getUrl('view', ['record' => $this->record]);
    }
}
