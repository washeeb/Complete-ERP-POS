<?php

namespace App\Filament\Resources\Alterations\Pages;

use App\Domain\Fashion\AlterationService;
use App\Filament\Resources\Alterations\AlterationResource;
use App\Filament\Support\Erp;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAlterations extends ListRecords
{
    protected static string $resource = AlterationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New alteration')->using(fn (array $data) => Erp::run(fn ($actor) => app(AlterationService::class)->open($actor,
            $data + ['charge_minor' => Money::toMinor((string) ($data['charge'] ?? '0'), Erp::user()->tenant->currency_code)])))];
    }
}
