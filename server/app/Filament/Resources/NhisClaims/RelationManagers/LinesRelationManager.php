<?php

namespace App\Filament\Resources\NhisClaims\RelationManagers;

use App\Filament\Support\FinanceUi;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The sales on a claim: receipt, patient, card number and the NHIS part. */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Sales on this claim';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true; // whoever may open the claim
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sale.customer', 'payment'))
            ->columns([
                TextColumn::make('sale.occurred_at')->label('Date')->dateTime('j M Y H:i'),
                TextColumn::make('sale.receipt_no')->label('Receipt'),
                TextColumn::make('sale.customer.name')->label('Patient'),
                TextColumn::make('payment.reference')->label('NHIS no.'),
                TextColumn::make('amount_minor')->label('NHIS part')->formatStateUsing(fn ($state) => FinanceUi::money($state))->alignEnd(),
            ])
            ->defaultSort(fn ($query) => $query->orderBy('created_at'))
            ->emptyStateHeading('No sales on this claim')
            ->emptyStateDescription(fn () => $this->getOwnerRecord()->status === 'withdrawn' ? 'It was withdrawn: its sales went back to "not claimed yet".' : null)
            ->paginated([25, 50, 100])
            ->recordUrl(null);
    }
}
