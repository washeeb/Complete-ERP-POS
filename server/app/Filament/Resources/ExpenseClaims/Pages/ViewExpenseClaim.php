<?php

namespace App\Filament\Resources\ExpenseClaims\Pages;

use App\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use Filament\Resources\Pages\ViewRecord;

class ViewExpenseClaim extends ViewRecord
{
    protected static string $resource = ExpenseClaimResource::class;

    protected function getHeaderActions(): array
    {
        return ExpenseClaimResource::claimActions();
    }
}
