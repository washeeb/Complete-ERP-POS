<?php

namespace App\Filament\Resources\AiRequests\Pages;

use App\Domain\Ai\AiGateway;
use App\Filament\Resources\AiRequests\AiRequestResource;
use App\Filament\Support\Erp;
use App\Support\Settings;
use Filament\Resources\Pages\ListRecords;

class ListAiRequests extends ListRecords
{
    protected static string $resource = AiRequestResource::class;

    public function getSubheading(): ?string
    {
        $tenant = Erp::user()->tenant_id;
        $cap = (int) app(Settings::class)->get('ai.monthly_cap_usd', $tenant);

        return 'Cloud AI spent this month: '.AiRequestResource::usd(app(AiGateway::class)->spentThisMonth($tenant)).' of US$ '.$cap.' budget. Local models cost nothing.';
    }
}
