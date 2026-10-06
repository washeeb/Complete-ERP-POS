<?php

namespace App\Filament\Resources\ExpenseClaims\Pages;

use App\Domain\Expenses\ExpenseService;
use App\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Branch;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateExpenseClaim extends CreateRecord
{
    protected static string $resource = ExpenseClaimResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return Erp::run(fn ($actor) => app(ExpenseService::class)->submit($actor, Branch::findOrFail($data['branch_id']), [
            'spent_on' => $data['spent_on'], 'account_id' => $data['account_id'], 'description' => $data['description'], 'payee' => $data['payee'] ?? null,
            'amount_minor' => FinanceUi::toMinor($data['amount']), 'receipt_path' => $data['receipt_path'] ?? null,
        ]));
    }

    protected function getRedirectUrl(): string
    {
        return ExpenseClaimResource::getUrl('view', ['record' => $this->record]);
    }
}
