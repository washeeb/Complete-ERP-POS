<?php

namespace App\Filament\Resources\SerialNumbers;

use App\Domain\Modules\ModuleService;
use App\Filament\Resources\SerialNumbers\Pages\ListSerialNumbers;
use App\Filament\Resources\SerialNumbers\Pages\ViewSerialNumber;
use App\Filament\Support\Erp;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SerialNumber;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** Every serial / IMEI number: where it is, who bought it, its warranty and its history (read from the stock ledger). */
class SerialNumberResource extends Resource
{
    protected static ?string $model = SerialNumber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = 'Electronics';

    protected static ?string $navigationLabel = 'Serial numbers';

    protected static ?string $modelLabel = 'serial number';

    protected static ?string $recordTitleAttribute = 'serial';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.view') && ModuleService::enabledAnywhere('electronics');
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

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('serial')->copyable(),
                TextEntry::make('product.name')->label('Product'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => SerialNumber::STATUSES[$state] ?? $state),
                TextEntry::make('where')->label('Where')->state(fn (SerialNumber $r) => $r->warehouse_id ? Warehouse::whereKey($r->warehouse_id)->value('name') : null)->placeholder('—'),
                TextEntry::make('sold_at')->label('Sold')->dateTime()->placeholder('—'),
                TextEntry::make('receipt')->label('Receipt')->state(fn (SerialNumber $r) => $r->sale_id ? Sale::whereKey($r->sale_id)->value('receipt_no') : null)->placeholder('—'),
                TextEntry::make('customer')->state(fn (SerialNumber $r) => $r->customer_id ? Customer::whereKey($r->customer_id)->value('name') : null)->placeholder('—'),
                TextEntry::make('warranty_until')->label('Warranty until')->date()->placeholder('—')
                    ->belowContent(fn (SerialNumber $r) => $r->warranty_until ? ($r->inWarranty() ? 'In warranty' : 'Expired') : null),
            ]),
            Section::make('History')->schema([
                TextEntry::make('history')->hiddenLabel()->html()->state(fn (SerialNumber $r) => '<table style="width:100%">'.DB::table('stock_movement_serials as l')
                    ->join('stock_movements as m', 'm.id', '=', 'l.stock_movement_id')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')
                    ->where('l.serial_number_id', $r->id)->orderBy('m.occurred_at')->get(['m.occurred_at', 'm.type', 'm.reason', 'm.actor_name', 'w.code'])
                    ->map(fn ($m) => '<tr><td>'.e(Carbon::parse($m->occurred_at)->format('j M Y H:i')).'</td><td>'.e(str_replace('_', ' ', $m->type)).'</td><td>'
                        .e($m->code).'</td><td>'.e($m->reason).'</td><td>'.e($m->actor_name).'</td></tr>')->implode('').'</table>'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('serial')->searchable()->copyable(),
                TextColumn::make('product.name')->label('Product')->searchable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => SerialNumber::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['in_stock' => 'success', 'sold' => 'info', 'in_transit' => 'warning'][$state] ?? 'gray'),
                TextColumn::make('where')->label('Where')->state(fn (SerialNumber $r) => $r->warehouse_id ? Warehouse::whereKey($r->warehouse_id)->value('code') : null)->placeholder('—'),
                TextColumn::make('warranty_until')->label('Warranty until')->date()->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->options(SerialNumber::STATUSES)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSerialNumbers::route('/'), 'view' => ViewSerialNumber::route('/{record}')];
    }
}
