<?php

namespace App\Filament\Resources\EmployeeLoans;

use App\Domain\Hr\HrService;
use App\Filament\Resources\EmployeeLoans\Pages\ListEmployeeLoans;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Staff loans and salary advances, repaid through payroll. */
class EmployeeLoanResource extends Resource
{
    protected static ?string $model = EmployeeLoan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static string|UnitEnum|null $navigationGroup = 'HR & payroll';

    protected static ?string $navigationLabel = 'Loans & advances';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('loans.manage') || Erp::canAnywhere('payroll.prepare') || Erp::canAnywhere('payroll.approve')) && config('erp.node.role') !== 'branch';
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
        return parent::getEloquentQuery()->with('employee');
    }

    public static function issueAction(): Action
    {
        return Action::make('issue')->label('Give a loan or advance')->icon(Heroicon::OutlinedPlus)->visible(fn () => Erp::canAnywhere('loans.manage'))
            ->schema([
                Select::make('employee_id')->label('Employee')->required()->searchable()->options(fn () => Employee::where('status', 'active')->orderBy('name')->pluck('name', 'id')),
                Select::make('kind')->required()->options(EmployeeLoan::KINDS)->default('advance'),
                TextInput::make('amount')->required()->numeric()->minValue(0.01),
                TextInput::make('monthly')->label('Deduct each month')->required()->numeric()->minValue(0.01),
                DatePicker::make('first_month')->label('First deduction in the payroll of')->required()->default(now()->startOfMonth()),
                Select::make('account_id')->label('Paid from')->required()->options(fn () => FinanceUi::moneyAccounts())
                    ->default(fn () => Account::where('system_key', 'cash_office')->value('id')),
                DatePicker::make('issued_on')->label('Paid on')->required()->default(now())->maxDate(now()),
                TextInput::make('note')->maxLength(255),
            ])
            ->action(fn (array $data) => Erp::run(fn ($actor) => app(HrService::class)->issueLoan($actor, Employee::findOrFail($data['employee_id']), $data['kind'],
                FinanceUi::toMinor($data['amount']), FinanceUi::toMinor($data['monthly']), $data['first_month'], Account::findOrFail($data['account_id']), $data['issued_on'],
                $data['note'] ?? null), 'Loan recorded'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('employee.name')->label('Employee')->searchable(),
                TextColumn::make('kind')->formatStateUsing(fn ($state) => EmployeeLoan::KINDS[$state] ?? $state),
                TextColumn::make('issued_on')->label('Paid on')->date()->sortable(),
                TextColumn::make('principal_minor')->label('Amount')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('monthly_minor')->label('Per month')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('balance')->label('Still owed')->alignEnd()->weight('bold')
                    ->state(fn (EmployeeLoan $r) => $r->principal_minor - $r->repaidMinor())->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
            ])
            ->defaultSort('issued_on', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListEmployeeLoans::route('/')];
    }
}
