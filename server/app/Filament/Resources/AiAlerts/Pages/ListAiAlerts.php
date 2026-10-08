<?php

namespace App\Filament\Resources\AiAlerts\Pages;

use App\Domain\Analytics\InsightAlerts;
use App\Filament\Resources\AiAlerts\AiAlertResource;
use App\Filament\Support\Erp;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListAiAlerts extends ListRecords
{
    protected static string $resource = AiAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')->label('Refresh now')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => Erp::branchIds('insights.view') === null)
                ->action(function () {
                    $open = app(InsightAlerts::class)->run(Erp::user()->tenant_id);
                    Notification::make()->title(array_sum($open).' open alert(s)')->success()->send();
                }),
        ];
    }
}
