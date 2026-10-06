<?php

namespace App\Filament\Resources\Accounts;

use App\Domain\Finance\AccountService;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** The chart of accounts (Ghana SME template to start; owner decision 2026-10-12). */
class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Chart of accounts';

    protected static ?string $modelLabel = 'account';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('finance.view') && config('erp.node.role') !== 'branch';
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('finance.accounts.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('finance.accounts.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false; // deactivate instead
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('code')->required()->maxLength(20),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('type')->required()->options(Account::TYPES),
            Select::make('parent_id')->label('Under heading')->options(fn () => Account::where('is_header', true)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->label()])),
            Toggle::make('is_header')->label('Heading (groups other accounts; nothing posts to it)'),
            Toggle::make('is_active')->label('Active')->default(true),
            Select::make('system_key')->label('Used by automatic posting for')->columnSpanFull()
                ->options(fn () => collect(AccountService::systemKeys(Erp::user()->tenant_id))->map(fn ($k) => $k[0])->all())
                ->helperText('Moving a purpose here takes it away from the account that had it.'),
            TextInput::make('description')->maxLength(255)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        $balances = function () {
            static $b = null;

            return $b ??= DB::table('journal_lines')->groupBy('account_id')->selectRaw('account_id, SUM(amount_minor) AS s')->pluck('s', 'account_id');
        };

        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->weight(fn (Account $record) => $record->is_header ? 'bold' : null),
                TextColumn::make('name')->searchable()->weight(fn (Account $record) => $record->is_header ? 'bold' : null)
                    ->description(fn (Account $record) => $record->control ? 'Kept by: '.Account::CONTROLS[$record->control] : null),
                TextColumn::make('type')->badge()->formatStateUsing(fn ($state) => Account::TYPES[$state] ?? $state),
                TextColumn::make('system_key')->label('Automatic posting')->placeholder('—')
                    ->formatStateUsing(fn ($state) => AccountService::systemKeys(Erp::user()->tenant_id)[$state][0] ?? $state),
                TextColumn::make('balance')->label('Balance (Dr + / Cr −)')->alignEnd()
                    ->state(fn (Account $record) => $record->is_header ? null : (int) ($balances()[$record->id] ?? 0))
                    ->formatStateUsing(fn ($state) => FinanceUi::money((int) $state))->placeholder(''),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([SelectFilter::make('type')->options(Account::TYPES)])
            ->defaultSort('code')
            ->paginated([50, 100, 'all'])->defaultPaginationPageOption(100)
            ->recordActions([
                Action::make('ledger')->label('Ledger')->icon(Heroicon::OutlinedBookOpen)->color('gray')->visible(fn (Account $record) => ! $record->is_header)
                    ->url(fn (Account $record) => route('filament.admin.pages.financial-reports', ['report' => 'ledger', 'account' => $record->id])),
                EditAction::make()->using(fn (Account $record, array $data) => Erp::run(fn ($actor) => app(AccountService::class)->save($actor, $record, $data))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAccounts::route('/')];
    }
}
