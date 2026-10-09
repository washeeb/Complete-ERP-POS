<?php

namespace App\Filament\Resources\FiscalSubmissions\Pages;

use App\Domain\Fiscal\FiscalService;
use App\Filament\Resources\FiscalSubmissions\FiscalSubmissionResource;
use App\Filament\Support\Erp;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListFiscalSubmissions extends ListRecords
{
    protected static string $resource = FiscalSubmissionResource::class;

    public function getSubheading(): ?string
    {
        return 'Mode: '.(FiscalService::MODES[app(FiscalService::class)->mode(Erp::user()->tenant_id)] ?? '').' (System → Settings → GRA E-VAT).';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('batch')->label('Send the waiting sales now')->icon(Heroicon::OutlinedPaperAirplane)->requiresConfirmation()
                ->visible(fn () => app(FiscalService::class)->mode(Erp::user()->tenant_id) === 'daily' && Erp::canAnywhere('finance.journal'))
                ->action(fn () => Notification::make()->title(app(FiscalService::class)->batch(Erp::user()->tenant_id).' sale(s) accepted by GRA')->success()->send()),
        ];
    }
}
