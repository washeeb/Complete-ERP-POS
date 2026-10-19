<?php

namespace App\Filament\Resources\GoodsReceipts;

use App\Domain\Purchasing\GoodsReceiptService;
use App\Filament\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Filament\Resources\GoodsReceipts\Pages\ViewGoodsReceipt;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\GlobalSearch;
use App\Models\GoodsReceipt;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Goods received notes: posted ones are read-only; held ones wait for a manager; mistakes are reversed. */
class GoodsReceiptResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'supplier_ref', 'supplier.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Supplier' => $record->supplier?->name, 'Delivery note' => $record->supplier_ref]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['supplier']);
    }

    protected static ?string $model = GoodsReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Goods received';

    protected static ?string $modelLabel = 'goods received note';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('purchasing.view') || Erp::canAnywhere('stock.receive');
    }

    public static function canCreate(): bool
    {
        return false; // received from a purchase order (or the handheld)
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
        $ids = Erp::branchIds('purchasing.view');
        $ids2 = Erp::branchIds('stock.receive');
        $all = $ids === null || $ids2 === null ? null : array_values(array_unique([...$ids, ...$ids2]));

        return parent::getEloquentQuery()->with('supplier', 'warehouse', 'purchaseOrder')->when($all !== null, fn ($q) => $q->whereIn('branch_id', $all));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', 'held')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    private static function money(?int $minor, GoodsReceipt $g): string
    {
        return Money::display((int) $minor, $g->currency_code);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('GRN'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => GoodsReceipt::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'posted' => 'success', 'held' => 'warning', default => 'gray'
                    }),
                TextEntry::make('purchaseOrder.number')->label('Purchase order')->placeholder('none'),
                TextEntry::make('supplier.name')->label('Supplier'),
                TextEntry::make('warehouse.name')->label('Warehouse'),
                TextEntry::make('supplier_ref')->label('Supplier ref.')->placeholder('—'),
                TextEntry::make('received_by_name')->asPerson('received_by')->label('Received by')->belowContent(fn (GoodsReceipt $record) => $record->received_at->format('j M Y H:i')),
                TextEntry::make('approved_by_name')->asPerson('approved_by')->label('Over-delivery accepted by')->placeholder('—'),
                TextEntry::make('net_minor')->label('Value before tax')->weight('bold')->formatStateUsing(fn ($state, GoodsReceipt $record) => self::money($state, $record)),
                TextEntry::make('reversal_reason')->label('Reversed / refused because')->visible(fn (GoodsReceipt $record) => $record->status === 'reversed')
                    ->belowContent(fn (GoodsReceipt $record) => $record->reversed_by_name)->columnSpan(3),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('name')->columnSpan(2)->formatStateUsing(fn ($state, $record) => "{$record->sku} · {$state}"),
                    TextEntry::make('qty')->formatStateUsing(fn ($state, $record) => Fields::qty($state, $record->uom_code)),
                    TextEntry::make('unit_cost_minor')->label('Cost')->formatStateUsing(fn ($state, $livewire) => self::money($state, $livewire->getRecord())),
                    TextEntry::make('bin.code')->label('Bin')->placeholder('—'),
                ]),
            ]),
        ]);
    }

    /** @return list<Action> */
    public static function receiptActions(): array
    {
        $svc = fn () => app(GoodsReceiptService::class);

        return [
            Action::make('accept')->label('Accept delivery')->icon(Heroicon::OutlinedCheck)->color('success')
                ->visible(fn (GoodsReceipt $record) => $record->status === 'held' && Erp::can('stock.receive.approve', $record->warehouse))
                ->requiresConfirmation()->modalDescription('The full delivery, including the quantity above the order, goes into stock.')
                ->action(fn (GoodsReceipt $record) => Erp::run(fn ($actor) => $svc()->approveHeld($actor, $record), 'Accepted and posted')),
            Action::make('refuse')->label('Refuse delivery')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn (GoodsReceipt $record) => $record->status === 'held' && Erp::can('stock.receive.approve', $record->warehouse))
                ->modalDescription('Nothing goes into stock. Receive the correct quantity again on the purchase order.')
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (GoodsReceipt $record, array $data) => Erp::run(fn ($actor) => $svc()->refuseHeld($actor, $record, $data['reason']), 'Refused')),
            Action::make('reverse')->label('Reverse')->icon(Heroicon::OutlinedArrowUturnLeft)->color('danger')
                ->visible(fn (GoodsReceipt $record) => $record->status === 'posted' && Erp::can('stock.receive.approve', $record->warehouse))
                ->modalDescription('Takes these goods back out of stock and reopens the order quantities. The note itself is kept, marked reversed.')
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (GoodsReceipt $record, array $data) => Erp::run(fn ($actor) => $svc()->reverse($actor, $record, $data['reason']), 'Reversed')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('GRN')->searchable()->sortable(),
                TextColumn::make('purchaseOrder.number')->label('Order')->searchable()->placeholder('—'),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('warehouse.code')->label('Warehouse'),
                TextColumn::make('net_minor')->label('Value')->alignEnd()->formatStateUsing(fn ($state, GoodsReceipt $record) => self::money($state, $record)),
                TextColumn::make('received_at')->label('Received')->since()->sortable()->description(fn (GoodsReceipt $record) => $record->received_by_name),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => GoodsReceipt::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'posted' => 'success', 'held' => 'warning', default => 'gray'
                    }),
            ])
            ->filters([SelectFilter::make('status')->options(GoodsReceipt::STATUSES)])
            ->defaultSort('received_at', 'desc')
            ->recordActions([ViewAction::make(), ...self::receiptActions()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListGoodsReceipts::route('/'), 'view' => ViewGoodsReceipt::route('/{record}')];
    }
}
