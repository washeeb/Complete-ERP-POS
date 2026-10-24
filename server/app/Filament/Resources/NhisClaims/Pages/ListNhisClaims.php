<?php

namespace App\Filament\Resources\NhisClaims\Pages;

use App\Filament\Resources\NhisClaims\NhisClaimResource;
use Filament\Resources\Pages\ListRecords;

class ListNhisClaims extends ListRecords
{
    protected static string $resource = NhisClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [NhisClaimResource::prepareAction()];
    }
}
