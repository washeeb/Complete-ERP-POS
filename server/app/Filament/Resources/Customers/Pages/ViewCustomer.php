<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\CrmDeals\CrmDealResource;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\CrmUi;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCustomer extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [...CustomerResource::accountActions(),
            CrmUi::followUpAction('customer_id'), // CRM add-on
            Action::make('newDeal')->label('New deal')->icon(Heroicon::OutlinedBriefcase)->color('gray')->visible(fn () => CrmUi::canManage())
                ->url(fn () => CrmDealResource::getUrl('create', ['customer' => $this->record->getKey()])),
            EditAction::make()];
    }
}
