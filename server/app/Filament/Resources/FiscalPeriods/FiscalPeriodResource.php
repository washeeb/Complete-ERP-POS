<?php

namespace App\Filament\Resources\FiscalPeriods;

use App\Domain\Finance\GeneralLedger;
use App\Domain\Finance\PeriodService;
use App\Filament\Resources\FiscalPeriods\Pages\ListFiscalPeriods;
use App\Filament\Support\Erp;
use App\Models\FiscalPeriod;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Months of the financial year: close when everything ties; only the GM reopens (owner decision 2026-10-12). */
class FiscalPeriodResource extends Resource
{
    protected static ?string $model = FiscalPeriod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Month-end';

    protected static ?string $modelLabel = 'month';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('finance.view') && config('erp.node.role') !== 'branch';
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('starts_on')->label('Month')->formatStateUsing(fn ($state) => $state->format('F Y'))->sortable(),
                TextColumn::make('year')->label('Financial year')->formatStateUsing(fn ($state, FiscalPeriod $record) => "FY{$state} · month {$record->period}"),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ucfirst($state))->color(fn ($state) => $state === 'closed' ? 'success' : 'warning'),
                TextColumn::make('closed_by_name')->label('Closed by')->placeholder('—')
                    ->description(fn (FiscalPeriod $record) => $record->closed_at?->format('j M Y H:i')),
            ])
            ->filters([SelectFilter::make('year')->label('Financial year')->options(fn () => FiscalPeriod::distinct()->orderBy('year')->pluck('year', 'year'))])
            ->defaultSort('starts_on')
            ->paginated([12, 24, 'all'])->defaultPaginationPageOption(12)
            ->headerActions([
                Action::make('year')->label('Set up a financial year')->icon(Heroicon::OutlinedPlus)->color('gray')
                    ->visible(fn () => Erp::canAnywhere('finance.close'))
                    ->schema([TextInput::make('year')->label('Year (the calendar year it ends in)')->required()->numeric()->minValue(2000)->maxValue(2100)->default(now()->year)])
                    ->action(fn (array $data) => app(GeneralLedger::class)->period(Erp::user()->tenant_id, sprintf('%04d-06-15', (int) $data['year']))),
            ])
            ->recordActions([
                Action::make('close')->label('Close month')->icon(Heroicon::OutlinedLockClosed)->color('success')
                    ->visible(fn (FiscalPeriod $record) => $record->status === 'open' && Erp::canAnywhere('finance.close'))
                    ->requiresConfirmation()
                    ->modalDescription(function (FiscalPeriod $record) {
                        $b = app(PeriodService::class)->blockers($record);

                        return $b ? 'Not ready yet: '.implode(' ', $b) : 'Everything dated in this month is posted and the ledger ties to its sub-ledgers. Once closed, nothing more can be posted into it.';
                    })
                    ->action(fn (FiscalPeriod $record) => Erp::run(fn ($actor) => app(PeriodService::class)->close($actor, $record), "{$record->label()} closed")),
                Action::make('reopen')->label('Reopen')->icon(Heroicon::OutlinedLockOpen)->color('danger')
                    ->visible(fn (FiscalPeriod $record) => $record->status === 'closed' && Erp::canAnywhere('finance.reopen'))
                    ->schema([TextInput::make('reason')->required()->maxLength(255)])
                    ->action(fn (FiscalPeriod $record, array $data) => Erp::run(fn ($actor) => app(PeriodService::class)->reopen($actor, $record, $data['reason']), "{$record->label()} reopened")),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFiscalPeriods::route('/')];
    }
}
