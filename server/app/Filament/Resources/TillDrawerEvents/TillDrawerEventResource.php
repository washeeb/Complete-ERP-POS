<?php

namespace App\Filament\Resources\TillDrawerEvents;

use App\Filament\Resources\TillDrawerEvents\Pages\ListTillDrawerEvents;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\TillDrawerEvent;
use App\Support\Money;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use UnitEnum;

/** Everything done to till drawers besides sales: expenses paid out, pay-ins, cash drops, no-sale opens, cash-limit warnings. */
class TillDrawerEventResource extends Resource
{
    protected static ?string $model = TillDrawerEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Till drawer';

    protected static ?int $navigationSort = 3;

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

        return parent::getEloquentQuery()->with('account', 'shift.till', 'shift.branch')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('where')->label('Till')->state(fn (TillDrawerEvent $r) => "{$r->shift->branch->code} · {$r->shift->till->code}"),
                TextColumn::make('kind')->label('What')->badge()->formatStateUsing(fn ($state) => TillDrawerEvent::KINDS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'payout' => 'warning', 'drop' => 'info', 'payin' => 'success', 'limit_alert' => 'danger', default => 'gray'
                    }),
                TextColumn::make('amount_minor')->label('Amount')->state(fn (TillDrawerEvent $r) => $r->kind === 'no_sale' ? null
                    : Money::display($r->amount_minor, $r->shift->currency_code))->placeholder('—')->alignEnd(),
                TextColumn::make('account.name')->label('Spent on')->placeholder('—'),
                TextColumn::make('reason')->label('Why / what for')->limit(40)->tooltip(fn (TillDrawerEvent $r) => $r->payee ? "Paid to {$r->payee}" : null)->placeholder('—'),
                TextColumn::make('user_name')->asPerson('user_id')->label('By'),
                TextColumn::make('approved_by_name')->label('Approved by')->placeholder('—'),
                TextColumn::make('receipt')->label('Receipt')->state(fn (TillDrawerEvent $r) => $r->expense_claim_id && $r->receipt_path ? 'Open' : null)->placeholder('—')
                    ->url(fn (TillDrawerEvent $r) => $r->expense_claim_id && $r->receipt_path ? route('expense.receipt', $r->expense_claim_id) : null, shouldOpenInNewTab: true)->color('primary'),
                TextColumn::make('offline')->label('Offline')->formatStateUsing(fn ($state) => $state ? 'yes' : '')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')->label('What')->options(TillDrawerEvent::KINDS),
                SelectFilter::make('branch_id')->label('Branch')->options(fn () => Branch::orderBy('code')->pluck('name', 'id')),
                Filter::make('period')->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($w, $d) => $w->where('occurred_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($w, $d) => $w->where('occurred_at', '<', Carbon::parse($d)->addDay()))),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListTillDrawerEvents::route('/')];
    }
}
