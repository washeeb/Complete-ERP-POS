<?php

namespace App\Filament\Resources\Promotions\Pages;

use App\Domain\Pricing\PromotionService;
use App\Filament\Resources\Promotions\PromotionResource;
use App\Filament\Support\Erp;
use App\Models\Promotion;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePromotion extends CreateRecord
{
    protected static string $resource = PromotionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(PromotionService::class)->save($actor, new Promotion, PromotionResource::toDomain($data)));
    }
}
