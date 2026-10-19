<?php

namespace App\Filament\Resources\Waybills;

use App\Domain\Logistics\WaybillService;
use App\Filament\Resources\Waybills\Pages\CreateWaybill;
use App\Filament\Resources\Waybills\Pages\ListWaybills;
use App\Filament\Resources\Waybills\Pages\ViewWaybill;
use App\Filament\Support\Erp;
use App\Filament\Support\GlobalSearch;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\StockTransfer;
use App\Models\SupplierReturn;
use App\Models\Waybill;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Waybills: goods to customers, between branches, back to suppliers, and the supplier's waybill on goods received. */
class WaybillResource extends Resource
{
    use GlobalSearch;

    protected static ?string $model = Waybill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Waybills';

    protected static ?int $navigationSort = 9;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'source_number', 'reference', 'to_name', 'vehicle_no'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['To' => $record->to_name, 'For' => $record->source_number, 'Status' => Waybill::STATUSES[$record->status] ?? $record->status]);
    }

    public static function canViewAny(): bool
    {
        return collect(WaybillService::PERMISSIONS)->contains(fn ($p) => Erp::canAnywhere($p)) || Erp::canAnywhere('stock.view');
    }

    public static function canCreate(): bool
    {
        return collect(WaybillService::PERMISSIONS)->contains(fn ($p) => Erp::canAnywhere($p));
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
        $ids = Erp::branchIds('stock.view');

        return parent::getEloquentQuery()->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    /** Recent documents of a kind that can travel with a waybill. */
    public static function sources(?string $kind): array
    {
        $since = now()->subDays(120);

        return match ($kind) {
            'delivery' => Delivery::with('customer')->where('created_at', '>=', $since)->latest()->limit(300)->get()->mapWithKeys(fn ($d) => [$d->id => "{$d->number} · {$d->customer?->name}"])->all(),
            'transfer' => StockTransfer::with('toWarehouse')->where('created_at', '>=', $since)->latest()->limit(300)->get()->mapWithKeys(fn ($t) => [$t->id => "{$t->number} → {$t->toWarehouse?->name}"])->all(),
            'supplier_return' => SupplierReturn::with('supplier')->where('status', '<>', 'cancelled')->where('created_at', '>=', $since)->latest()->limit(300)->get()
                ->mapWithKeys(fn ($r) => [$r->id => "{$r->number} · {$r->supplier?->name}"])->all(),
            'inbound' => GoodsReceipt::with('supplier')->where('created_at', '>=', $since)->latest()->limit(300)->get()->mapWithKeys(fn ($g) => [$g->id => "{$g->number} · {$g->supplier?->name}"])->all(),
            default => [],
        };
    }

    public static function form(Schema $schema): Schema
    {
        $inbound = fn (Get $get) => $get('kind') === 'inbound';

        return $schema->columns(1)->components([
            Section::make('What it carries')->columns(2)->schema([
                Select::make('kind')->label('Waybill for')->options(Waybill::KINDS)->required()->live()->afterStateUpdated(fn ($set) => $set('source_id', null)),
                Select::make('source_id')->label('Document')->required()->searchable()->options(fn (Get $get) => self::sources($get('kind')))
                    ->helperText('The goods are copied from it, as sent.'),
                TextInput::make('reference')->label(fn (Get $get) => $get('kind') === 'inbound' ? 'Supplier\'s waybill number' : 'Transporter\'s reference (optional)')
                    ->required($inbound)->maxLength(80),
            ]),
            Section::make('Transport')->columns(3)->schema([
                TextInput::make('transporter')->label('Transport company')->maxLength(120),
                TextInput::make('driver_name')->label('Driver')->maxLength(120),
                TextInput::make('driver_phone')->label('Driver\'s phone')->tel()->maxLength(30),
                TextInput::make('vehicle_no')->label('Vehicle registration')->maxLength(30)->placeholder('GR 1234-24'),
                TextInput::make('packages')->label('Packages')->numeric()->minValue(1),
                TextInput::make('weight_kg')->label('Weight (kg)')->numeric()->minValue(0),
            ]),
            Section::make('Delivery')->columns(2)->hidden($inbound)->schema([
                TextInput::make('to_address')->label('Deliver to (address or directions; empty = on file)')->maxLength(255),
                TextInput::make('to_phone')->label('Receiver\'s phone (empty = on file)')->tel()->maxLength(30),
                Textarea::make('notes')->columnSpanFull()->maxLength(500),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Waybill'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => Waybill::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['delivered' => 'success', 'dispatched' => 'warning', 'cancelled' => 'gray'][$state] ?? 'info'),
                TextEntry::make('kind')->label('For')->formatStateUsing(fn ($state, Waybill $r) => (Waybill::KINDS[$state] ?? $state)." · {$r->source_number}"),
                TextEntry::make('reference')->label('Their reference')->placeholder('—'),
                TextEntry::make('from_name')->label('From')->belowContent(fn (Waybill $r) => $r->from_address),
                TextEntry::make('to_name')->label('To')->belowContent(fn (Waybill $r) => trim(($r->to_address ?? '').' '.($r->to_phone ?? ''))),
                TextEntry::make('vehicle')->label('Vehicle and driver')->state(fn (Waybill $r) => collect([$r->vehicle_no, $r->driver_name, $r->driver_phone, $r->transporter])->filter()->implode(' · ') ?: '—'),
                TextEntry::make('packages')->label('Packages / weight')->state(fn (Waybill $r) => ($r->packages ?? '—').' / '.($r->weight_kg ? "{$r->weight_kg} kg" : '—')),
                TextEntry::make('created_by_name')->label('Prepared by')->belowContent(fn (Waybill $r) => $r->created_at?->format('j M Y H:i')),
                TextEntry::make('dispatched_by_name')->label('Dispatched')->placeholder('—')->belowContent(fn (Waybill $r) => $r->dispatched_at?->format('j M Y H:i')),
                TextEntry::make('received_by_name')->label('Received by')->placeholder('—')
                    ->belowContent(fn (Waybill $r) => $r->delivered_at ? $r->delivered_at->format('j M Y H:i').($r->pod_via === 'link' ? ' · confirmed by the receiver (QR)' : '') : null),
                TextEntry::make('pod')->label('Proof of delivery')->state(fn (Waybill $r) => $r->pod_path ? 'Open' : '—')
                    ->url(fn (Waybill $r) => $r->pod_path ? route('print.waybill.pod', $r) : null, true),
                TextEntry::make('pod_note')->label('Receiver\'s note')->placeholder('—')->columnSpan(2),
                TextEntry::make('notes')->placeholder('—')->columnSpan(2),
                TextEntry::make('cancel_reason')->label('Cancelled because')->visible(fn (Waybill $r) => $r->status === 'cancelled'),
            ]),
            Section::make('Goods')->schema([
                TextEntry::make('goods')->hiddenLabel()->html()->state(fn (Waybill $r) => new HtmlString('<table style="width:100%;font-size:.9rem">'
                    .collect($r->lines)->map(fn ($l) => '<tr><td>'.e($l['sku']).'</td><td>'.e($l['name']).'</td><td style="text-align:right">'
                        .e(rtrim(rtrim($l['qty'], '0'), '.').' '.$l['unit']).'</td></tr>')->implode('').'</table>')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('created_at')->label('Date')->date('j M Y'),
                TextColumn::make('kind')->label('For')->formatStateUsing(fn ($state) => Waybill::KINDS[$state] ?? $state)->description(fn (Waybill $r) => $r->source_number),
                TextColumn::make('to_name')->label('To')->searchable()->wrap(),
                TextColumn::make('vehicle_no')->label('Vehicle')->placeholder('—')->searchable()->description(fn (Waybill $r) => $r->driver_name),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Waybill::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['delivered' => 'success', 'dispatched' => 'warning', 'cancelled' => 'gray'][$state] ?? 'info'),
            ])
            ->filters([SelectFilter::make('status')->options(Waybill::STATUSES), SelectFilter::make('kind')->label('For')->options(Waybill::KINDS)])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWaybills::route('/'), 'create' => CreateWaybill::route('/create'), 'view' => ViewWaybill::route('/{record}')];
    }
}
