<?php

namespace App\Filament\Resources\Sales;

use App\Filament\Resources\Sales\Pages\ListSales;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\Money;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Till sales';

    protected static ?string $recordTitleAttribute = 'receipt_no';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('sales.view');
    }

    public static function canView(Model $record): bool
    {
        return Erp::can('sales.view', Branch::find($record->branch_id));
    }

    public static function canCreate(): bool
    {
        return false; // sales come from tills
    }

    public static function canEdit(Model $record): bool
    {
        return false; // posted sales are immutable; returns arrive with POS returns
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('sales.view');

        return parent::getEloquentQuery()->with('branch', 'till', 'payments')
            ->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    private static function money(int $minor, Sale $sale): string
    {
        return Money::display($minor, $sale->currency_code);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('receipt_no')->label('Receipt'),
                TextEntry::make('occurred_at')->label('Sold at')->dateTime(),
                TextEntry::make('cashier_name')->asPerson('cashier_id')->label('Teller'),
                TextEntry::make('where')->label('Where')->state(fn (Sale $r) => "{$r->branch->code} · till {$r->till->code}"),
                TextEntry::make('net_minor')->label('Before tax')->formatStateUsing(fn ($state, Sale $r) => self::money($state, $r)),
                TextEntry::make('tax_minor')->label('Tax')->formatStateUsing(fn ($state, Sale $r) => self::money($state, $r)),
                TextEntry::make('total_minor')->label('Total')->weight('bold')->formatStateUsing(fn ($state, Sale $r) => self::money($state, $r)),
                TextEntry::make('change_minor')->label('Change given')->formatStateUsing(fn ($state, Sale $r) => self::money($state, $r)),
                TextEntry::make('discount_minor')->label('Discounts')->formatStateUsing(fn ($state, Sale $r) => self::money($state, $r))
                    ->belowContent(fn (Sale $r) => trim(($r->coupon_code ? "coupon {$r->coupon_code} " : '').($r->discount_approved_by_name ? "approved by {$r->discount_approved_by_name}" : '')) ?: null),
                TextEntry::make('kind')->label('Type')->badge()->formatStateUsing(fn ($state, Sale $r) => $state === 'return' ? 'Return'.($r->return_reason ? ": {$r->return_reason}" : '') : 'Sale')
                    ->color(fn ($state) => $state === 'return' ? 'warning' : 'gray'),
                TextEntry::make('points_earned')->label('Points earned')->visible(fn (Sale $r) => $r->points_earned > 0),
            ]),
            Section::make('Items')->schema([
                RepeatableEntry::make('lines')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('name')->columnSpan(2),
                    TextEntry::make('qty')->formatStateUsing(fn ($state, $record) => rtrim(rtrim(Qty::format(Qty::toMilli((string) $state)), '0'), '.').' '.$record->uom_code),
                    TextEntry::make('unit_price_minor')->label('Unit price')->formatStateUsing(fn ($state, $record) => self::money($state, $record->sale)),
                    TextEntry::make('tax_minor')->label('Tax')->formatStateUsing(fn ($state, $record) => self::money($state, $record->sale)),
                    TextEntry::make('line_total_minor')->label('Line total')->formatStateUsing(fn ($state, $record) => self::money($state, $record->sale)),
                ]),
            ]),
            Grid::make(2)->schema([
                Section::make('Tax')->schema([
                    RepeatableEntry::make('tax_breakdown')->hiddenLabel()->columns(2)->schema([
                        TextEntry::make('name'),
                        TextEntry::make('amount_minor')->label('Amount')->formatStateUsing(fn ($state, $livewire) => Money::display((int) $state, $livewire->getRecord()->currency_code)),
                    ]),
                ]),
                Section::make('Payments')->schema([
                    RepeatableEntry::make('payments')->hiddenLabel()->columns(3)->schema([
                        TextEntry::make('method')->formatStateUsing(fn ($state, $record) => SalePayment::METHODS[$state].($record->provider ? ' · '.(SalePayment::MOMO_PROVIDERS[$record->provider] ?? $record->provider) : '')),
                        TextEntry::make('amount_minor')->label('Amount')->formatStateUsing(fn ($state, $record) => self::money($state, $record->sale)),
                        TextEntry::make('reference')->placeholder('—'),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('receipt_no')->label('Receipt')->searchable()->sortable()
                    ->description(fn (Sale $r) => $r->kind === 'return' ? 'Return' : null),
                TextColumn::make('occurred_at')->label('Sold at')->dateTime()->sortable(),
                TextColumn::make('branch.code')->label('Branch'),
                TextColumn::make('cashier_name')->asPerson('cashier_id')->label('Teller')->searchable(),
                TextColumn::make('total_minor')->label('Total')->sortable()->alignEnd()
                    ->formatStateUsing(fn ($state, Sale $r) => self::money($state, $r)),
                TextColumn::make('paid_by')->label('Paid by')
                    ->state(fn (Sale $r) => $r->payments->pluck('method')->unique()->map(fn ($m) => SalePayment::METHODS[$m])->implode(' + ')),
            ])
            ->filters([
                SelectFilter::make('branch_id')->label('Branch')->options(fn () => Branch::orderBy('code')->pluck('code', 'id')),
                Filter::make('today')->label('Today')->query(fn ($q) => $q->where('occurred_at', '>=', now()->startOfDay())),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
            'view' => ViewSale::route('/{record}'),
        ];
    }
}
