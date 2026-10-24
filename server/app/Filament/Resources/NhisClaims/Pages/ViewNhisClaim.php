<?php

namespace App\Filament\Resources\NhisClaims\Pages;

use App\Filament\Resources\NhisClaims\NhisClaimResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewNhisClaim extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = NhisClaimResource::class;

    protected function getHeaderActions(): array
    {
        return NhisClaimResource::claimActions();
    }
}
