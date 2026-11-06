<?php

namespace App\Filament\Resources\OfficeReturns;

use App\Domain\Pos\OfficeReturns;
use App\Domain\Pos\ReturnService;
use App\Filament\Pages\ReturnsReport;
use App\Filament\Resources\OfficeReturns\Pages\ListOfficeReturns;
use App\Filament\Resources\OfficeReturns\Pages\ViewOfficeReturn;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\MiniTable;
use App\Models\OfficeReturn;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Sales → Back-office returns: entered from a receipt, approved by someone else. See App\Domain\Pos\OfficeReturns. */
class OfficeReturnResource extends Resource
{
    protected static ?string $model = OfficeReturn::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Back-office returns';

    protected static ?string $modelLabel = 'back-office return';

    protected static ?int $navigationSort = 41;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('returns.office') || Erp::canAnywhere('returns.office.approve');
    }

    public static function canCreate(): bool
    {
        return false; // started from the sale
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** On a sale's page: the goods coming back, and how the customer is refunded. */
    public static function returnAction(): Action
    {
        return Action::make('officeReturn')->label('Return goods')->icon(Heroicon::OutlinedArrowUturnLeft)->color('warning')
            ->visible(fn (Sale $record) => $record->kind === 'sale' && $record->status === 'posted' && Erp::canAnywhere('returns.office'))
            ->modalDescription('A manager approves it before anything changes. Goods go to the shelf, or to quarantine if damaged, expired or a medicine.')
            ->fillForm(fn (Sale $record) => ['warehouse_id' => $record->warehouse_id, 'refund_method' => 'store_credit', 'lines' => collect(app(ReturnService::class)->returnable($record->load('lines', 'payments'))['lines'])
                ->filter(fn ($l) => (float) $l['returnable_qty'] > 0)->map(fn ($l) => ['line_id' => $l['id'], 'name' => $l['name'], 'can' => rtrim(rtrim($l['returnable_qty'], '0'), '.').' '.$l['uom'], 'qty' => null, 'damaged' => false])->values()->all()])
            ->schema([
                Repeater::make('lines')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->columns(6)->schema([
                    Hidden::make('line_id'),
                    TextInput::make('name')->label('Item')->disabled()->dehydrated(false)->columnSpan(3),
                    TextInput::make('can')->label('Can return')->disabled()->dehydrated(false),
                    TextInput::make('qty')->label('Return')->numeric()->minValue(0),
                    Toggle::make('damaged')->label('Damaged')->inline(false),
                ]),
                Select::make('warehouse_id')->label('Goes back to')->required()->selectablePlaceholder(false)->options(fn () => Fields::warehouses('returns.office')),
                Select::make('reason_code')->label('Reason')->required()->options(ReturnService::REASONS),
                TextInput::make('reason')->label('Details')->maxLength(255),
                Select::make('refund_method')->label('Refund as')->required()->selectablePlaceholder(false)->live()->options(OfficeReturn::REFUNDS),
                Select::make('account_id')->label('Paid from')->required()->options(fn () => FinanceUi::moneyAccounts())->visible(fn (Get $get) => $get('refund_method') === 'money'),
                TextInput::make('reference')->label('Reference')->required()->maxLength(80)->visible(fn (Get $get) => $get('refund_method') === 'money'),
            ])
            ->action(function (Sale $record, array $data, $livewire) {
                $doc = Erp::run(fn ($actor) => app(OfficeReturns::class)->submit($actor, $record, $data), 'Sent for approval');
                if ($doc) {
                    $livewire->redirect(self::getUrl('view', ['record' => $doc]));
                }
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state, OfficeReturn $record) => Money::display((int) $state, $record->currency_code);

        return $schema->columns(1)->components([
            Section::make(fn (OfficeReturn $r) => OfficeReturn::STATUSES[$r->status] ?? $r->status)->columns(4)->schema([
                TextEntry::make('original.receipt_no')->label('Receipt'),
                TextEntry::make('total_minor')->label('Refund')->formatStateUsing($money)->weight('bold'),
                TextEntry::make('refund_method')->label('Refund as')->formatStateUsing(fn ($state) => OfficeReturn::REFUNDS[$state] ?? $state),
                TextEntry::make('account.name')->label('Paid from')->placeholder('—'),
                TextEntry::make('reason_code')->label('Reason')->formatStateUsing(fn ($state) => ReturnService::REASONS[$state] ?? $state),
                TextEntry::make('reason')->label('Details')->placeholder('—'),
                TextEntry::make('reference')->placeholder('—'),
                TextEntry::make('warehouse.name')->label('Goes back to'),
                TextEntry::make('created_by_name')->label('Entered by'),
                TextEntry::make('posted_by_name')->label('Approved by')->placeholder('—'),
                TextEntry::make('sale.receipt_no')->label('Return no.')->placeholder('—'),
                TextEntry::make('cancel_reason')->label('Cancelled because')->placeholder('—'),
            ]),
            Section::make('Goods')->schema([
                TextEntry::make('goods')->hiddenLabel()->html()->state(function (OfficeReturn $r) {
                    $names = SaleLine::whereIn('id', collect($r->lines)->pluck('line_id'))->get()->keyBy('id');

                    return MiniTable::html(['Item', 'Quantity', 'Condition'], collect($r->lines)->map(fn ($l) => [$names[$l['line_id']]->name ?? '—', rtrim(rtrim((string) $l['qty'], '0'), '.').' '.($names[$l['line_id']]->uom_code ?? ''),
                        ! empty($l['damaged']) ? 'Damaged' : 'Good'])->all(), [], 'No lines.');
                }),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('original'))
            ->columns([
                TextColumn::make('number')->label('No.')->searchable()->sortable(),
                TextColumn::make('created_at')->label('Entered')->dateTime()->sortable(),
                TextColumn::make('original.receipt_no')->label('Receipt'),
                TextColumn::make('total_minor')->label('Refund')->alignEnd()->formatStateUsing(fn ($state, OfficeReturn $r) => Money::display((int) $state, $r->currency_code)),
                TextColumn::make('refund_method')->label('Refund as')->formatStateUsing(fn ($state) => OfficeReturn::REFUNDS[$state] ?? $state),
                TextColumn::make('reason_code')->label('Reason')->formatStateUsing(fn ($state) => ReturnService::REASONS[$state] ?? $state),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => OfficeReturn::STATUSES[$state] ?? $state)->color(fn ($state) => ['posted' => 'success', 'submitted' => 'warning'][$state] ?? 'gray'),
                TextColumn::make('created_by_name')->label('By'),
            ])
            ->filters([SelectFilter::make('status')->options(OfficeReturn::STATUSES)])
            ->defaultSort('created_at', 'desc');
    }

    /** @return list<Action> */
    public static function documentActions(): array
    {
        $svc = fn () => app(OfficeReturns::class);

        return [
            Action::make('approve')->label('Approve and refund')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (OfficeReturn $record) => $record->status === 'submitted' && $svc()->whyNotApprove(Erp::actor(), $record) === null)
                ->requiresConfirmation()->modalDescription(fn (OfficeReturn $record) => 'Books the return and refunds '.Money::display($record->total_minor, $record->currency_code).' as '.strtolower(OfficeReturn::REFUNDS[$record->refund_method]).'. This cannot be undone.')
                ->action(fn (OfficeReturn $record) => Erp::run(fn ($actor) => $svc()->approve($actor, $record), 'Return posted')),
            Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXMark)->color('gray')->visible(fn (OfficeReturn $record) => $record->status === 'submitted')
                ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
                ->action(fn (OfficeReturn $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
            Action::make('slip')->label('Return slip')->icon(Heroicon::OutlinedPrinter)->color('gray')->visible(fn (OfficeReturn $record) => $record->sale_id !== null)
                ->action(fn (OfficeReturn $record) => ReturnsReport::slip($record->sale)),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListOfficeReturns::route('/'), 'view' => ViewOfficeReturn::route('/{record}')];
    }
}
