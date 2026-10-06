<?php

namespace App\Filament\Resources\JournalEntries;

use App\Domain\Finance\JournalService;
use App\Filament\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Resources\JournalEntries\Pages\ViewJournalEntry;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\JournalEntry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Every posted journal: automatic ones from the other modules and those typed by finance. */
class JournalEntryResource extends Resource
{
    protected static ?string $model = JournalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Journals';

    protected static ?string $modelLabel = 'journal';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('finance.view') && config('erp.node.role') !== 'branch';
    }

    public static function canCreate(): bool
    {
        return false; // the "New journal" action posts through the journal service
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** The journal form: date, description and debit/credit lines. */
    public static function journalForm(bool $opening = false): array
    {
        return [
            DatePicker::make('date')->required()->default(now())->maxDate(now()),
            TextInput::make('memo')->label('Description')->required(! $opening)->maxLength(255)->visible(! $opening),
            Repeater::make('lines')->columns(5)->minItems($opening ? 1 : 2)->defaultItems(2)->addActionLabel('Add line')->schema([
                Select::make('account_id')->label('Account')->required()->searchable()->options(fn () => FinanceUi::accounts(withControl: false))->columnSpan(2),
                TextInput::make('debit')->numeric()->minValue(0),
                TextInput::make('credit')->numeric()->minValue(0),
                Select::make('branch_id')->label('Branch')->options(fn () => FinanceUi::branches())->default(fn () => FinanceUi::hq()),
            ]),
        ];
    }

    public static function toLines(array $data): array
    {
        return array_map(fn ($l) => ['account_id' => $l['account_id'], 'debit_minor' => FinanceUi::toMinor($l['debit'] ?? null),
            'credit_minor' => FinanceUi::toMinor($l['credit'] ?? null), 'branch_id' => $l['branch_id'] ?? null], array_values($data['lines'] ?? []));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('entry_date')->label('Date')->date()->sortable(),
                TextColumn::make('kind')->badge()->formatStateUsing(fn ($state) => JournalEntry::KINDS[$state] ?? $state)
                    ->color(fn ($state) => $state === 'auto' ? 'gray' : 'info'),
                TextColumn::make('memo')->label('Description')->searchable()->wrap(),
                TextColumn::make('total_minor')->label('Amount')->alignEnd()->formatStateUsing(fn ($state) => FinanceUi::money((int) $state)),
                TextColumn::make('posted_by_name')->label('Posted by')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([SelectFilter::make('kind')->options(JournalEntry::KINDS)])
            ->defaultSort('number', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('entry_date')->label('Date')->date(),
                TextEntry::make('kind')->badge()->formatStateUsing(fn ($state) => JournalEntry::KINDS[$state] ?? $state),
                TextEntry::make('posted_by_name')->label('Posted by'),
                TextEntry::make('memo')->label('Description')->columnSpanFull(),
                TextEntry::make('reverses.number')->label('Reverses')->placeholder('—'),
                TextEntry::make('reversed_by')->label('Reversed by')->placeholder('—')
                    ->state(fn (JournalEntry $record) => JournalEntry::where('reverses_id', $record->id)->value('number')),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('account.code')->label('Account')->formatStateUsing(fn ($state, $record) => $record->account->label())->columnSpan(2),
                    TextEntry::make('branch.code')->label('Branch')->placeholder('—'),
                    TextEntry::make('debit')->state(fn ($record) => $record->amount_minor > 0 ? FinanceUi::money($record->amount_minor) : '')->alignEnd(),
                    TextEntry::make('credit')->state(fn ($record) => $record->amount_minor < 0 ? FinanceUi::money(-$record->amount_minor) : '')->alignEnd(),
                ]),
            ]),
        ]);
    }

    public static function reverseAction(): Action
    {
        return Action::make('reverse')->label('Reverse')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
            ->visible(fn (JournalEntry $record) => in_array($record->kind, ['manual', 'opening'], true) && Erp::canAnywhere('finance.journal')
                && ! JournalEntry::where('reverses_id', $record->id)->exists())
            ->schema([DatePicker::make('date')->required()->default(now())->maxDate(now()), TextInput::make('reason')->required()->maxLength(200)])
            ->action(fn (JournalEntry $record, array $data) => Erp::run(fn ($actor) => app(JournalService::class)->reverse($actor, $record, $data['date'], $data['reason']),
                'Journal reversed'));
    }

    public static function getPages(): array
    {
        return ['index' => ListJournalEntries::route('/'), 'view' => ViewJournalEntry::route('/{record}')];
    }
}
