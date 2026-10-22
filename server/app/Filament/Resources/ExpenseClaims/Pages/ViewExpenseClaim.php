<?php

namespace App\Filament\Resources\ExpenseClaims\Pages;

use App\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use App\Filament\Support\ReloadsAfterAction;
use Filament\Resources\Pages\ViewRecord;

class ViewExpenseClaim extends ViewRecord
{
    use ReloadsAfterAction;

    protected static string $resource = ExpenseClaimResource::class;

    protected function getHeaderActions(): array
    {
        return ExpenseClaimResource::claimActions();
    }
}
