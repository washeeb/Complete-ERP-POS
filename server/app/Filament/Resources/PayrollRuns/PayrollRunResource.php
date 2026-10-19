<?php

namespace App\Filament\Resources\PayrollRuns;

use App\Domain\Hr\PayrollService;
use App\Filament\Resources\PayrollRuns\Pages\ListPayrollRuns;
use App\Filament\Resources\PayrollRuns\Pages\ViewPayrollRun;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\GlobalSearch;
use App\Models\Account;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Payslip;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Monthly payroll: prepare → approve (someone else) → pay. */
class PayrollRunResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Status' => $record->status]);
    }

    protected static ?string $model = PayrollRun::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'HR & payroll';

    protected static ?string $navigationLabel = 'Payroll';

    protected static ?string $modelLabel = 'payroll';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('payroll.prepare') || Erp::canAnywhere('payroll.approve') || Erp::canAnywhere('payroll.pay')) && config('erp.node.role') !== 'branch';
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

    public static function prepareAction(?PayrollRun $run = null): Action
    {
        return Action::make($run ? 'recalculate' : 'prepare')->label($run ? 'Recalculate' : 'Prepare payroll')->icon(Heroicon::OutlinedCalculator)
            ->color($run ? 'gray' : 'primary')
            ->visible(fn () => Erp::canAnywhere('payroll.prepare') && (! $run || $run->status === 'draft'))
            ->schema($run ? [] : [
                Select::make('month')->required()->options(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => date('F', mktime(0, 0, 0, $m, 1))]))->default(now()->month),
                TextInput::make('year')->required()->numeric()->minValue(2000)->maxValue(2100)->default(now()->year),
                DatePicker::make('pay_date')->label('Pay day')->default(now()->endOfMonth()),
            ])
            ->action(function (array $data, $livewire) use ($run) {
                $r = Erp::run(fn ($actor) => app(PayrollService::class)->prepare($actor, $run?->year ?? (int) $data['year'], $run?->month ?? (int) $data['month'],
                    $run?->pay_date?->toDateString() ?? ($data['pay_date'] ?? null)), 'Payroll calculated');
                $livewire->redirect(self::getUrl('view', ['record' => $r]));
            });
    }

    public static function runActions(): array
    {
        return [
            Action::make('approve')->label('Approve')->icon(Heroicon::OutlinedCheck)->color('success')->requiresConfirmation()
                ->modalDescription('Approving locks the payslips and posts the payroll to the books (wages, SSNIT, PAYE, Tier 2 and 3, loan repayments).')
                ->visible(fn (PayrollRun $r) => $r->status === 'draft' && Erp::canAnywhere('payroll.approve') && $r->prepared_by !== Erp::user()?->id)
                ->action(fn (PayrollRun $record) => Erp::run(fn ($actor) => app(PayrollService::class)->approve($actor, $record), 'Payroll approved')),
            Action::make('pay')->label('Record payment')->icon(Heroicon::OutlinedBanknotes)->color('success')
                ->visible(fn (PayrollRun $r) => $r->status === 'approved' && Erp::canAnywhere('payroll.pay'))
                ->schema([
                    Select::make('bank_account_id')->label('Bank and MoMo pay from')->options(fn () => FinanceUi::moneyAccounts())->default(fn () => Account::where('system_key', 'bank')->value('id')),
                    Select::make('cash_account_id')->label('Cash pay from')->options(fn () => FinanceUi::moneyAccounts())->default(fn () => Account::where('system_key', 'cash_office')->value('id')),
                    DatePicker::make('paid_on')->required()->default(now())->maxDate(now()),
                ])
                ->action(fn (PayrollRun $record, array $data) => Erp::run(fn ($actor) => app(PayrollService::class)->pay($actor, $record,
                    Account::find($data['bank_account_id'] ?? null), Account::find($data['cash_account_id'] ?? null), $data['paid_on']), 'Payroll paid')),
            Action::make('payslips')->label('Payslips')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->url(fn (PayrollRun $r) => route('print.payslips', $r), shouldOpenInNewTab: true),
            Action::make('sheet')->label('Pay sheet')->icon(Heroicon::OutlinedClipboardDocumentList)->color('gray')
                ->url(fn (PayrollRun $r) => route('print.pay-sheet', $r), shouldOpenInNewTab: true),
            Action::make('list')->label('Bank / MoMo list (CSV)')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->url(fn (PayrollRun $r) => route('print.pay-list', $r)),
        ];
    }

    public static function table(Table $table): Table
    {
        $m = fn ($key) => fn (PayrollRun $r) => FinanceUi::money((int) ($r->totals[$key] ?? 0));

        return $table
            ->columns([
                TextColumn::make('number'),
                TextColumn::make('period_start')->label('Month')->formatStateUsing(fn ($state) => $state->format('F Y'))->sortable(),
                TextColumn::make('people')->label('Staff')->state(fn (PayrollRun $r) => $r->totals['n'] ?? 0),
                TextColumn::make('gross')->label('Gross')->alignEnd()->state($m('gross_minor')),
                TextColumn::make('paye')->label('PAYE')->alignEnd()->state($m('paye_minor')),
                TextColumn::make('net')->label('Net pay')->alignEnd()->weight('bold')->state($m('net_minor')),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => PayrollRun::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'paid' => 'success', 'approved' => 'info', default => 'warning'
                    }),
            ])
            ->defaultSort('period_start', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $t = fn (string $key) => fn (PayrollRun $r) => FinanceUi::money((int) ($r->totals[$key] ?? 0));
        $m = fn ($state) => FinanceUi::money((int) $state);

        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('period_start')->label('Month')->formatStateUsing(fn ($state) => $state->format('F Y')),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => PayrollRun::STATUSES[$state] ?? $state),
                TextEntry::make('pay_date')->label('Pay day')->date(),
                TextEntry::make('gross')->label('Gross pay')->state($t('gross_minor')),
                TextEntry::make('paye')->label('PAYE')->state($t('paye_minor')),
                TextEntry::make('ssnit')->label('SSNIT (employee + employer)')->state(fn (PayrollRun $r) => FinanceUi::money((int) (($r->totals['ssnit_employee_minor'] ?? 0) + ($r->totals['ssnit_employer_minor'] ?? 0)))),
                TextEntry::make('net')->label('Net pay')->weight('bold')->state($t('net_minor')),
                TextEntry::make('prepared_by_name')->asPerson('prepared_by')->label('Prepared by'),
                TextEntry::make('approved_by_name')->label('Approved by')->placeholder('—'),
                TextEntry::make('paid_by_name')->label('Paid')->placeholder('—')->formatStateUsing(fn ($state, PayrollRun $r) => $state ? "{$state}, {$r->paid_on?->format('j M Y')}" : null),
                TextEntry::make('profile')->label('Tax and SSNIT rules')->state(fn (PayrollRun $r) => $r->profile->name)
                    ->belowContent(fn (PayrollRun $r) => $r->profile->verified_at ? "Checked by {$r->profile->verified_by_name}" : 'NOT YET CHECKED BY AN ACCOUNTANT')
                    ->color(fn (PayrollRun $r) => $r->profile->verified_at ? null : 'danger'),
            ]),
            Section::make('Payslips')->schema([
                RepeatableEntry::make('payslips')->hiddenLabel()->columns(7)->schema([
                    TextEntry::make('employee.name')->label('Employee')->columnSpan(2)
                        ->url(fn (Payslip $record) => route('print.payslip', $record), shouldOpenInNewTab: true),
                    TextEntry::make('gross_minor')->label('Gross')->formatStateUsing($m),
                    TextEntry::make('ssnit_employee_minor')->label('SSNIT')->formatStateUsing($m),
                    TextEntry::make('paye_minor')->label('PAYE')->formatStateUsing($m),
                    TextEntry::make('net_minor')->label('Net')->weight('bold')->formatStateUsing($m),
                    TextEntry::make('employee.pay_method')->label('Paid by')->formatStateUsing(fn ($state) => Employee::PAY_METHODS[$state] ?? $state),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPayrollRuns::route('/'), 'view' => ViewPayrollRun::route('/{record}')];
    }
}
