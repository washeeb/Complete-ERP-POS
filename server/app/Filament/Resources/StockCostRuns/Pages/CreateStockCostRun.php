<?php

namespace App\Filament\Resources\StockCostRuns\Pages;

use App\Domain\Inventory\StockCostRuns;
use App\Filament\Resources\StockCostRuns\StockCostRunResource;
use App\Filament\Support\Erp;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockCostRun extends CreateRecord
{
    protected static string $resource = StockCostRunResource::class;

    protected static ?string $title = 'New cost run';

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(StockCostRuns::class)->prepare($actor, $data));
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Worked out: check the preview';
    }

    protected function getRedirectUrl(): string
    {
        return StockCostRunResource::getUrl('view', ['record' => $this->record]);
    }
}
