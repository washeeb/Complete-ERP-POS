<?php

namespace App\Filament\Resources\StockTransfers;

use App\Domain\Inventory\TransferService;
use App\Filament\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\EditStockTransfer;
use App\Filament\Resources\StockTransfers\Pages\ListStockTransfers;
use App\Filament\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class StockTransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Transfers';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.transfer') || Erp::canAnywhere('stock.view');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('stock.transfer');
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'draft' && Erp::can('stock.transfer', $record->fromWarehouse);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('stock.view');

        return parent::getEloquentQuery()->with('fromWarehouse', 'toWarehouse')
            ->when($ids !== null, fn ($q) => $q->where(fn ($q) => $q->whereIn('from_branch_id', $ids)->orWhereIn('to_branch_id', $ids)));
    }

    public static function getNavigationBadge(): ?string
    {
        $ids = Erp::branchIds('stock.transfer');
        $n = StockTransfer::where('status', 'in_transit')->when($ids !== null, fn ($q) => $q->whereIn('to_branch_id', $ids))->count();

        return $n ? (string) $n : null;
    }

    public static function toFormData(StockTransfer $t): array
    {
        return ['lines' => $t->lines()->get()->map(fn ($l) => ['product_uom_id' => $l->product_uom_id, 'qty' => Fields::qty($l->qty)])->all()];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(2)->schema([
                Select::make('from_warehouse_id')->label('From')->required()->options(fn () => Fields::warehouses('stock.transfer')),
                Select::make('to_warehouse_id')->label('To')->required()->different('from_warehouse_id')
                    ->options(fn () => Warehouse::where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($w) => [$w->id => "{$w->code} · {$w->name}"])),
                Textarea::make('note')->columnSpanFull(),
            ]),
            Section::make('Lines')->schema([
                Repeater::make('lines')->hiddenLabel()->columns(3)->minItems(1)->addActionLabel('Add line')->schema([
                    Fields::productUnit()->columnSpan(2),
                    TextInput::make('qty')->label('Quantity')->required()->numeric()->minValue(0.001),
                ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Transfer'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => StockTransfer::STATUSES[$state] ?? $state),
                TextEntry::make('fromWarehouse.name')->label('From'),
                TextEntry::make('toWarehouse.name')->label('To'),
                TextEntry::make('created_by_name')->label('Created by'),
                TextEntry::make('dispatched_by_name')->label('Dispatched by')->placeholder('—')->belowContent(fn (StockTransfer $record) => $record->dispatched_at?->format('j M Y H:i')),
                TextEntry::make('received_by_name')->label('Received by')->placeholder('—')->belowContent(fn (StockTransfer $record) => $record->received_at?->format('j M Y H:i')),
                TextEntry::make('has_discrepancy')->label('Arrived')->formatStateUsing(fn ($state) => $state ? 'Different from sent — see the review queue' : 'As sent')
                    ->color(fn ($state) => $state ? 'danger' : 'success')->visible(fn (StockTransfer $record) => $record->status === 'received'),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('name')->columnSpan(2)->formatStateUsing(fn ($state, $record) => "{$record->sku} · {$state}"),
                    TextEntry::make('qty')->label('Sent')->formatStateUsing(fn ($state, $record) => Fields::qty($state, $record->uom_code)),
                    TextEntry::make('received_qty')->label('Received')->placeholder('—')->formatStateUsing(fn ($state, $record) => Fields::qty($state, $record->uom_code)),
                ]),
            ]),
        ]);
    }

    /** @return list<Action> */
    public static function transferActions(): array
    {
        $svc = fn () => app(TransferService::class);

        return [
            Action::make('dispatch')->label('Dispatch')->icon(Heroicon::OutlinedTruck)->color('success')
                ->visible(fn (StockTransfer $record) => $record->status === 'draft' && Erp::can('stock.transfer', $record->fromWarehouse))
                ->requiresConfirmation()->modalDescription('Takes the goods out of the sending warehouse now. They show as in transit until received.')
                ->action(fn (StockTransfer $record) => Erp::run(fn ($actor) => $svc()->dispatch($actor, $record), 'Dispatched')),
            Action::make('receive')->label('Receive')->icon(Heroicon::OutlinedInboxArrowDown)->color('success')
                ->visible(fn (StockTransfer $record) => $record->status === 'in_transit' && Erp::can('stock.transfer', $record->toWarehouse))
                ->fillForm(fn (StockTransfer $record) => ['lines' => $record->lines()->get()->map(fn ($l) => ['line_id' => $l->id,
                    'item' => "{$l->sku} · {$l->name} ({$l->uom_code})", 'qty' => Fields::qty($l->qty)])->all()])
                ->schema(fn (StockTransfer $record) => [
                    Repeater::make('lines')->columns(3)->addable(false)->deletable(false)->reorderable(false)->schema([
                        TextInput::make('item')->disabled()->dehydrated(false)->columnSpan(2),
                        TextInput::make('qty')->label('Arrived')->numeric()->minValue(0)->required(),
                        Hidden::make('line_id'),
                    ]),
                    Select::make('to_bin_id')->label('Into bin')->options(Fields::bins($record->to_warehouse_id))
                        ->visible($record->toWarehouse->use_bins)->placeholder('Receiving dock'),
                    Textarea::make('note')->label('Note (e.g. what was damaged)'),
                ])
                ->action(fn (StockTransfer $record, array $data) => Erp::run(fn ($actor) => $svc()->receive($actor, $record,
                    collect($data['lines'])->pluck('qty', 'line_id')->map(fn ($q) => (string) $q)->all(), $data['to_bin_id'] ?? null, $data['note'] ?? null), 'Received')),
            Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (StockTransfer $record) => $record->status === 'draft' && Erp::can('stock.transfer', $record->fromWarehouse))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (StockTransfer $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Transfer')->searchable()->sortable(),
                TextColumn::make('fromWarehouse.code')->label('From'),
                TextColumn::make('toWarehouse.code')->label('To'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => StockTransfer::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'in_transit' => 'warning', 'received' => 'success', 'cancelled' => 'gray', default => null
                    }),
                IconColumn::make('has_discrepancy')->label('Difference')->boolean()->trueColor('danger')->falseIcon(null),
                TextColumn::make('dispatched_at')->label('Dispatched')->since()->placeholder('—')->sortable(),
                TextColumn::make('created_at')->label('Created')->since()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(StockTransfer::STATUSES)])
            ->defaultSort('created_at', 'desc')
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockTransfers::route('/'),
            'create' => CreateStockTransfer::route('/create'),
            'view' => ViewStockTransfer::route('/{record}'),
            'edit' => EditStockTransfer::route('/{record}/edit'),
        ];
    }
}
