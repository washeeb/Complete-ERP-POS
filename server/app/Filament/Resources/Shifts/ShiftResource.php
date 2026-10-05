<?php

namespace App\Filament\Resources\Shifts;

use App\Filament\Resources\Shifts\Pages\ListShifts;
use App\Filament\Support\Erp;
use App\Models\Shift;
use App\Support\Money;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ShiftResource extends Resource
{
    protected static ?string $model = Shift::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Till shifts';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('sales.view');
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
        $ids = Erp::branchIds('sales.view');

        return parent::getEloquentQuery()->with('branch', 'till')->withCount('sales')
            ->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function table(Table $table): Table
    {
        $m = fn ($state, Shift $r) => $state === null ? '—' : Money::display((int) $state, $r->currency_code);

        return $table
            ->columns([
                TextColumn::make('where')->label('Till')->state(fn (Shift $r) => "{$r->branch->code} · {$r->till->code}"),
                TextColumn::make('opened_by_name')->label('Opened by'),
                TextColumn::make('opened_at')->label('Opened')->dateTime()->sortable(),
                TextColumn::make('closed_at')->label('Closed')->dateTime()->placeholder('still open'),
                TextColumn::make('sales_count')->label('Sales'),
                TextColumn::make('opening_float_minor')->label('Float')->formatStateUsing($m),
                TextColumn::make('expected_cash_minor')->label('Expected cash')->formatStateUsing($m),
                TextColumn::make('counted_cash_minor')->label('Counted')->formatStateUsing($m),
                TextColumn::make('over_short_minor')->label('Over / short')->formatStateUsing($m)
                    ->color(fn ($state) => $state === null ? null : ($state < 0 ? 'danger' : ($state > 0 ? 'warning' : 'success'))),
                TextColumn::make('close_note')->label('Note')->limit(40)->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(['open' => 'Open', 'closed' => 'Closed'])])
            ->defaultSort('opened_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListShifts::route('/')];
    }
}
