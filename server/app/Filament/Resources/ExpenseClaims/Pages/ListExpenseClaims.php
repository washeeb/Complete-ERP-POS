<?php

namespace App\Filament\Resources\ExpenseClaims\Pages;

use App\Filament\Resources\ExpenseClaims\ExpenseClaimResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListExpenseClaims extends ListRecords
{
    protected static string $resource = ExpenseClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New claim')];
    }
}
