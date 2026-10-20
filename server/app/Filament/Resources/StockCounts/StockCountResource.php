<?php

namespace App\Filament\Resources\StockCounts;

use App\Domain\Inventory\StockCountService;
use App\Filament\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Resources\StockCounts\Pages\ViewStockCount;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\GlobalSearch;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\StockCount;
use App\Models\Tenant;
use App\Support\Money;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
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

class StockCountResource extends Resource
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

    protected static ?string $model = StockCount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Stock counts';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.count') || Erp::canAnywhere('stock.count.approve');
    }

    public static function canCreate(): bool
    {
        return false; // started with the "Start a count" action
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
        $a = Erp::branchIds('stock.count');
        $b = Erp::branchIds('stock.count.approve');
        $ids = $a === null || $b === null ? null : array_values(array_unique([...$a, ...$b]));

        return parent::getEloquentQuery()->with('warehouse', 'bin')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()->where('status', 'submitted')->count();

        return $n ? (string) $n : null;
    }

    /** Expected figures are hidden from counters on a blind count until it is submitted. */
    public static function showsExpected(StockCount $c): bool
    {
        return ! $c->blind || $c->status !== 'counting' || Erp::can('stock.count.approve', $c->warehouse);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Count'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => StockCount::STATUSES[$state] ?? $state),
                TextEntry::make('warehouse.name')->label('Warehouse')->belowContent(fn (StockCount $record) => $record->bin ? "Bin {$record->bin->code}" : 'Whole warehouse'),
                TextEntry::make('blind')->label('Type')->formatStateUsing(fn ($state) => $state ? 'Blind' : 'Expected figures shown'),
                TextEntry::make('started_by_name')->label('Started by')->belowContent(fn (StockCount $record) => $record->started_at->format('j M Y H:i')),
                TextEntry::make('submitted_by_name')->asPerson('submitted_by')->label('Submitted by')->placeholder('—'),
                TextEntry::make('posted_by_name')->label('Posted by')->placeholder('—'),
                TextEntry::make('variance_value_minor')->label('Difference value')->placeholder('—')
                    ->formatStateUsing(fn ($state, StockCount $record) => Money::display((int) $state, Tenant::find($record->tenant_id)->currency_code)),
            ]),
            Section::make('Lines')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(5)->schema([
                    TextEntry::make('product.name')->label('Product')->columnSpan(2)->formatStateUsing(fn ($state, $record) => "{$record->product->sku} · {$state}"),
                    TextEntry::make('bin.code')->label('Bin')->placeholder('—'),
                    TextEntry::make('counted_qty')->label('Counted')->placeholder('not counted')->formatStateUsing(fn ($state) => Fields::qty($state)),
                    TextEntry::make('expected_qty')->label('Expected / difference')
                        ->visible(fn ($livewire) => self::showsExpected($livewire->getRecord()))
                        ->formatStateUsing(fn ($state, $record) => Fields::qty($state).($record->counted_qty === null ? '' : ' / '.sprintf('%+g',
                            (Qty::toMilli((string) $record->counted_qty) - Qty::toMilli((string) $state)) / 1000))),
                ]),
            ]),
        ]);
    }

    /** @return list<Action> */
    public static function countActions(): array
    {
        $svc = fn () => app(StockCountService::class);

        return [
            Action::make('count')->label('Enter a count')->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn (StockCount $record) => $record->status === 'counting' && Erp::can('stock.count', $record->warehouse))
                ->schema(fn (StockCount $record) => [
                    Select::make('bin_id')->label('Bin')->options(Fields::bins($record->warehouse_id))
                        ->visible($record->warehouse->use_bins && ! $record->bin_id)->required($record->warehouse->use_bins && ! $record->bin_id),
                    Repeater::make('items')->itemNumbers()->hiddenLabel()->columns(3)->minItems(1)->defaultItems(1)->addActionLabel('Add another product')->schema([
                        Fields::productUnit()->columnSpan(2),
                        TextInput::make('qty')->label('Counted')->numeric()->minValue(0)->required(),
                    ]),
                ])
                ->action(function (StockCount $record, array $data) use ($svc) {
                    foreach ($data['items'] as $i) {
                        $unit = ProductUom::findOrFail($i['product_uom_id']);
                        Erp::run(fn ($actor) => $svc()->record($actor, $record, Product::findOrFail($unit->product_id), $unit, (string) $i['qty'], $data['bin_id'] ?? null));
                    }
                    Notification::make()->success()->title(count($data['items']).' count(s) saved')->send();
                }),
            Action::make('submit')->label('Submit for approval')->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (StockCount $record) => $record->status === 'counting' && Erp::can('stock.count', $record->warehouse))
                ->requiresConfirmation()->modalDescription('Products nobody counted are left unchanged (not set to zero).')
                ->action(fn (StockCount $record) => Erp::run(fn ($actor) => $svc()->submit($actor, $record), 'Submitted')),
            Action::make('post')->label('Approve and post')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (StockCount $record) => $record->status === 'submitted' && Erp::can('stock.count.approve', $record->warehouse))
                ->requiresConfirmation()->modalDescription('Books the differences into stock as count movements.')
                ->action(function (StockCount $record) use ($svc) {
                    $r = Erp::run(fn ($actor) => $svc()->post($actor, $record));
                    Notification::make()->success()->title("Posted: {$r['lines']} difference(s)")->send();
                }),
            Action::make('cancel')->label('Cancel count')->icon(Heroicon::OutlinedXCircle)->color('danger')
                ->visible(fn (StockCount $record) => in_array($record->status, ['counting', 'submitted'], true) && Erp::can('stock.count', $record->warehouse))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (StockCount $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Count')->searchable(),
                TextColumn::make('warehouse.code')->label('Warehouse')->description(fn (StockCount $record) => $record->bin?->code),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => StockCount::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'counting' => 'info', 'submitted' => 'warning', 'posted' => 'success', default => 'gray'
                    }),
                TextColumn::make('started_at')->label('Started')->since()->sortable()->description(fn (StockCount $record) => $record->started_by_name),
                TextColumn::make('lines_count')->counts('lines')->label('Lines'),
            ])
            ->filters([SelectFilter::make('status')->options(StockCount::STATUSES)])
            ->defaultSort('started_at', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStockCounts::route('/'), 'view' => ViewStockCount::route('/{record}')];
    }
}
