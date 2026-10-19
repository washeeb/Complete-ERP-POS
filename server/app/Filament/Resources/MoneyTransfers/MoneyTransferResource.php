<?php

namespace App\Filament\Resources\MoneyTransfers;

use App\Filament\Resources\MoneyTransfers\Pages\ListMoneyTransfers;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\GlobalSearch;
use App\Models\MoneyTransfer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Money moved between the company's own accounts. */
class MoneyTransferResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'reference'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Reference' => $record->reference]);
    }

    protected static ?string $model = MoneyTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Money moved';

    protected static ?int $navigationSort = 7;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('banking.manage') || Erp::canAnywhere('finance.view')) && config('erp.node.role') !== 'branch';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('fromAccount', 'toAccount');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->searchable(),
            TextColumn::make('moved_on')->label('Date')->date()->sortable(),
            TextColumn::make('fromAccount.name')->label('From'),
            TextColumn::make('toAccount.name')->label('To'),
            TextColumn::make('amount_minor')->label('Amount')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
            TextColumn::make('reference')->placeholder('—')->searchable(),
            TextColumn::make('moved_by_name')->label('By'),
        ])->defaultSort('moved_on', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListMoneyTransfers::route('/')];
    }
}
