<?php

namespace App\Filament\Resources\Promotions\Pages;

use App\Domain\Pricing\PromotionService;
use App\Filament\Resources\Promotions\PromotionResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPromotion extends EditRecord
{
    protected static string $resource = PromotionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return PromotionResource::toForm($this->record) + $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return Erp::run(fn ($actor) => app(PromotionService::class)->save($actor, $record, PromotionResource::toDomain($data)));
    }
}
