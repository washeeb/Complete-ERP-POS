<?php

namespace App\Filament\Resources\DrugInteractions\Pages;

use App\Domain\Pharmacy\PharmacyService;
use App\Filament\Resources\DrugInteractions\DrugInteractionResource;
use App\Filament\Support\Erp;
use App\Models\DrugInteraction;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDrugInteractions extends ListRecords
{
    protected static string $resource = DrugInteractionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => Erp::run(fn ($actor) => app(PharmacyService::class)->saveInteraction($actor, new DrugInteraction, $data)))];
    }
}
