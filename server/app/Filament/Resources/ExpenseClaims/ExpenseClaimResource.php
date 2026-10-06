<?php

namespace App\Filament\Resources\ExpenseClaims;

use App\Domain\Expenses\ExpenseService;
use App\Filament\Resources\ExpenseClaims\Pages\CreateExpenseClaim;
use App\Filament\Resources\ExpenseClaims\Pages\ListExpenseClaims;
use App\Filament\Resources\ExpenseClaims\Pages\ViewExpenseClaim;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\Branch;
use App\Models\ExpenseClaim;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Expense claims and petty cash (owner decision 2026-10-13). */
class ExpenseClaimResource extends Resource
{
    protected static ?string $model = ExpenseClaim::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Expense claims';

    protected static ?string $modelLabel = 'expense claim';

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('expenses.claim') || Erp::canAnywhere('expenses.approve') || Erp::canAnywhere('expenses.pay') || Erp::canAnywhere('finance.view'))
            && config('erp.node.role') !== 'branch';
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('expenses.claim') && config('erp.node.role') !== 'branch';
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** Your own claims, plus every claim in branches where you approve or pay (finance sees all). */
    public static function getEloquentQuery(): Builder
    {
        $me = Erp::user()?->id;
        $scopes = array_map(fn ($p) => Erp::branchIds($p), ['expenses.approve', 'expenses.pay', 'finance.view']);
        $all = in_array(null, $scopes, true);
        $ids = $all ? null : array_values(array_unique(array_merge(...$scopes)));

        return parent::getEloquentQuery()->with('account', 'branch')
            ->when(! $all, fn ($q) => $q->where(fn ($w) => $w->where('claimant_id', $me)->orWhereIn('branch_id', $ids ?: ['00000000-0000-0000-0000-000000000000'])));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', 'submitted')->where('claimant_id', '<>', Erp::user()?->id)->count();

        return $n && Erp::canAnywhere('expenses.approve') ? (string) $n : null;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('branch_id')->label('Branch')->required()
                ->options(function () {
                    $ids = Erp::branchIds('expenses.claim');

                    return Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"]);
                }),
            DatePicker::make('spent_on')->label('Date spent')->required()->default(now())->maxDate(now()),
            Select::make('account_id')->label('What for')->required()->searchable()->options(fn () => FinanceUi::accounts(withControl: false, types: ['expense'])),
            TextInput::make('amount')->required()->numeric()->minValue(0.01),
            TextInput::make('description')->required()->maxLength(255)->columnSpanFull(),
            TextInput::make('payee')->label('Paid to (shop, person)')->maxLength(255),
            FileUpload::make('receipt_path')->label('Receipt photo')->disk('local')->directory('expense-receipts')->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])->maxSize(5120),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('spent_on')->label('Date')->date()->sortable(),
                TextColumn::make('claimant_name')->label('By')->searchable(),
                TextColumn::make('branch.code')->label('Branch'),
                TextColumn::make('description')->wrap()->description(fn (ExpenseClaim $r) => $r->account?->name),
                TextColumn::make('amount_minor')->label('Amount')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ExpenseClaim::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success', 'rejected' => 'danger', 'approved' => 'info', default => 'warning'
                    }),
            ])
            ->filters([SelectFilter::make('status')->options(ExpenseClaim::STATUSES)])
            ->defaultSort('number', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => ExpenseClaim::STATUSES[$state] ?? $state),
                TextEntry::make('claimant_name')->label('Claimed by'),
                TextEntry::make('branch.name')->label('Branch'),
                TextEntry::make('spent_on')->label('Date spent')->date(),
                TextEntry::make('account.name')->label('What for'),
                TextEntry::make('amount_minor')->label('Amount')->weight('bold')->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextEntry::make('payee')->label('Paid to')->placeholder('—'),
                TextEntry::make('description')->columnSpanFull(),
                TextEntry::make('receipt')->label('Receipt')->state(fn (ExpenseClaim $r) => $r->receipt_path ? 'Open the receipt' : 'No receipt attached')
                    ->url(fn (ExpenseClaim $r) => $r->receipt_path ? route('expense.receipt', $r) : null, shouldOpenInNewTab: true)->color(fn (ExpenseClaim $r) => $r->receipt_path ? 'primary' : 'danger'),
                TextEntry::make('approved_by_name')->label('Approved / rejected by')->placeholder('—'),
                TextEntry::make('rejection_reason')->label('Rejected because')->placeholder('—'),
                TextEntry::make('paid_by_name')->label('Paid by')->placeholder('—')
                    ->formatStateUsing(fn ($state, ExpenseClaim $r) => $state ? "{$state}, ".$r->paid_on?->format('j M Y').' from '.Account::find($r->paid_from_account_id)?->name : null),
            ]),
        ]);
    }

    public static function claimActions(): array
    {
        $me = fn () => Erp::user()?->id;

        return [
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                ->visible(fn (ExpenseClaim $r) => $r->status === 'submitted' && $r->claimant_id !== $me() && Erp::can('expenses.approve', $r->branch))
                ->action(fn (ExpenseClaim $record) => Erp::run(fn ($actor) => app(ExpenseService::class)->approve($actor, $record), 'Claim approved')),
            Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedXMark)->color('danger')
                ->visible(fn (ExpenseClaim $r) => in_array($r->status, ['submitted', 'approved'], true) && $r->claimant_id !== $me() && Erp::can('expenses.approve', $r->branch))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (ExpenseClaim $record, array $data) => Erp::run(fn ($actor) => app(ExpenseService::class)->reject($actor, $record, $data['reason']), 'Claim rejected')),
            Action::make('pay')->label('Pay')->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->visible(fn (ExpenseClaim $r) => $r->status === 'approved' && Erp::can('expenses.pay', $r->branch))
                ->schema([
                    Select::make('account_id')->label('Paid from')->required()->options(fn () => FinanceUi::moneyAccounts())
                        ->default(fn () => Account::where('system_key', 'petty_cash')->value('id')),
                    DatePicker::make('paid_on')->required()->default(now())->maxDate(now()),
                ])
                ->action(fn (ExpenseClaim $record, array $data) => Erp::run(fn ($actor) => app(ExpenseService::class)->pay($actor, $record, Account::findOrFail($data['account_id']),
                    $data['paid_on']), 'Claim paid')),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListExpenseClaims::route('/'), 'create' => CreateExpenseClaim::route('/create'), 'view' => ViewExpenseClaim::route('/{record}')];
    }
}
