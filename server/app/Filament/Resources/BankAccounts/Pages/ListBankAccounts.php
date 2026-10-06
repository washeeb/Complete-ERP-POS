<?php

namespace App\Filament\Resources\BankAccounts\Pages;

use App\Domain\Banking\BankingService;
use App\Filament\Resources\BankAccounts\BankAccountResource;
use App\Filament\Support\Erp;
use App\Models\BankAccount;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBankAccounts extends ListRecords
{
    protected static string $resource = BankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            BankAccountResource::transferAction(),
            CreateAction::make()->using(fn (array $data) => Erp::run(fn ($actor) => app(BankingService::class)->saveAccount($actor, new BankAccount, $data))),
        ];
    }
}
