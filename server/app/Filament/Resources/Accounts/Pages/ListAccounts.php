<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Domain\Finance\AccountService;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\Erp;
use App\Models\Account;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->using(fn (array $data) => Erp::run(fn ($actor) => app(AccountService::class)->save($actor, new Account, $data)))];
    }
}
