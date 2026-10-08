<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Domain\Manufacturing\WorkOrderService;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Support\Erp;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListWorkOrders extends ListRecords
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label('New work order')->icon(Heroicon::OutlinedPlus)->visible(fn () => Erp::canAnywhere('manufacturing.produce'))
                ->schema(WorkOrderResource::newOrderForm())
                ->action(function (array $data) {
                    $wo = Erp::run(fn ($actor) => app(WorkOrderService::class)->create($actor, $data), 'Work order created');
                    if ($wo) {
                        $this->redirect(WorkOrderResource::getUrl('view', ['record' => $wo]));
                    }
                }),
        ];
    }
}
