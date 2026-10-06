<?php

namespace App\Filament\Resources\BankAccounts;

use App\Domain\Banking\BankingService;
use App\Domain\Banking\StatementCsv;
use App\Filament\Pages\BankReconciliation;
use App\Filament\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\Branch;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/** Bank, MoMo and cash accounts: statements, reconciliation and moving money (CSV statements now; bank APIs later). */
class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Bank & cash';

    protected static ?string $modelLabel = 'bank or cash account';

    protected static ?int $navigationSort = 6;

    public static function canViewAny(): bool
    {
        return (Erp::canAnywhere('banking.manage') || Erp::canAnywhere('finance.view')) && config('erp.node.role') !== 'branch';
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('banking.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('banking.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255)->placeholder('e.g. GCB current account, MTN MoMo merchant'),
            Select::make('kind')->required()->options(BankAccount::KINDS)->default('bank'),
            TextInput::make('provider')->label('Bank / network')->maxLength(255),
            TextInput::make('number')->label('Account / wallet number')->maxLength(60),
            Select::make('account_id')->label('Ledger account')->options(fn () => collect(FinanceUi::accounts(withControl: false, types: ['asset']))->all())
                ->helperText('Leave blank to create a new ledger account with the code below.'),
            TextInput::make('code')->label('New ledger account code')->maxLength(20)->visibleOn('create'),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function importAction(): Action
    {
        return Action::make('import')->label('Import statement')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
            ->visible(fn () => Erp::canAnywhere('banking.manage'))->modalWidth('3xl')
            ->modalDescription('Export the statement from your bank or MoMo portal as CSV (in Excel: Save As → CSV). Name the columns once; they are remembered for this account. Lines already imported are skipped.')
            ->fillForm(fn (BankAccount $record) => ($record->csv_mapping ?? []) + ['header_rows' => 1, 'date_format' => 'auto'])
            ->schema([
                FileUpload::make('file')->label('Statement (CSV)')->required()->disk('local')->directory('statements')->visibility('private')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv'])->maxSize(10240)->storeFileNamesIn('file_name'),
                Section::make('Columns (header text or column number)')->columns(3)->schema([
                    TextInput::make('header_rows')->label('Header rows to skip')->numeric()->minValue(0)->maxValue(20)->required(),
                    TextInput::make('date')->required(),
                    Select::make('date_format')->options(StatementCsv::DATE_FORMATS)->required(),
                    TextInput::make('description'),
                    TextInput::make('reference'),
                    TextInput::make('amount')->label('Amount (+ in, − out)'),
                    TextInput::make('money_in')->label('…or money in'),
                    TextInput::make('money_out')->label('…and money out'),
                    TextInput::make('balance')->label('Balance (optional)'),
                ]),
            ])
            ->action(function (BankAccount $record, array $data) {
                $path = is_array($data['file']) ? reset($data['file']) : $data['file'];
                $contents = Storage::disk('local')->get($path);
                Storage::disk('local')->delete($path);
                $r = Erp::run(fn ($actor) => app(BankingService::class)->import($actor, $record, (string) ($data['file_name'] ?? basename($path)), (string) $contents, $data));
                Notification::make()->success()->title("{$r['added']} line(s) added".($r['skipped'] ? ", {$r['skipped']} already there" : ''))->send();
            });
    }

    public static function transferAction(): Action
    {
        return Action::make('transfer')->label('Move money')->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn () => Erp::canAnywhere('banking.manage'))
            ->modalDescription('Between your own accounts: banking the till cash, topping up petty cash, sweeping MoMo to the bank.')
            ->schema([
                Select::make('from_account_id')->label('From')->required()->options(fn () => FinanceUi::moneyAccounts()),
                Select::make('branch_id')->label('From branch')->required()->options(fn () => FinanceUi::branches())->default(fn () => FinanceUi::hq()),
                Select::make('to_account_id')->label('To')->required()->options(fn () => FinanceUi::moneyAccounts()),
                Select::make('to_branch_id')->label('To branch')->required()->options(fn () => FinanceUi::branches())->default(fn () => FinanceUi::hq()),
                TextInput::make('amount')->required()->numeric()->minValue(0.01),
                DatePicker::make('moved_on')->label('Date')->required()->default(now())->maxDate(now()),
                TextInput::make('reference')->label('Deposit slip / reference')->maxLength(80),
                TextInput::make('note')->maxLength(255),
            ])
            ->action(fn (array $data) => Erp::run(fn ($actor) => app(BankingService::class)->transfer($actor, Account::findOrFail($data['from_account_id']),
                Account::findOrFail($data['to_account_id']), Branch::findOrFail($data['branch_id']), Branch::findOrFail($data['to_branch_id']),
                FinanceUi::toMinor($data['amount']), $data['moved_on'], $data['reference'] ?? null, $data['note'] ?? null), 'Money moved'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (BankAccount $r) => trim(($r->provider ?? '').' '.($r->number ?? ''))),
                TextColumn::make('kind')->badge()->formatStateUsing(fn ($state) => BankAccount::KINDS[$state] ?? $state),
                TextColumn::make('account.code')->label('Ledger')->formatStateUsing(fn ($state, BankAccount $r) => $r->account->label()),
                TextColumn::make('balance')->label('Ledger balance')->alignEnd()
                    ->state(fn (BankAccount $r) => (int) DB::table('journal_lines')->where('account_id', $r->account_id)->sum('amount_minor'))
                    ->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('open_lines')->label('Statement lines to match')->alignEnd()
                    ->state(fn (BankAccount $r) => DB::table('bank_statement_lines')->where('bank_account_id', $r->id)->where('status', 'open')->count()),
            ])
            ->recordActions([
                Action::make('reconcile')->label('Reconcile')->icon(Heroicon::OutlinedCheckBadge)
                    ->url(fn (BankAccount $r) => BankReconciliation::getUrl(['bank' => $r->id])),
                self::importAction(),
                EditAction::make()->using(fn (BankAccount $record, array $data) => Erp::run(fn ($actor) => app(BankingService::class)->saveAccount($actor, $record, $data))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBankAccounts::route('/')];
    }
}
