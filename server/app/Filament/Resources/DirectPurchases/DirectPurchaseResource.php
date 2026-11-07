<?php

namespace App\Filament\Resources\DirectPurchases;

use App\Domain\Catalog\CatalogService;
use App\Domain\Purchasing\DirectPurchases;
use App\Filament\Resources\DirectPurchases\Pages\CreateDirectPurchase;
use App\Filament\Resources\DirectPurchases\Pages\EditDirectPurchase;
use App\Filament\Resources\DirectPurchases\Pages\ListDirectPurchases;
use App\Filament\Resources\DirectPurchases\Pages\ViewDirectPurchase;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\LabelsAction;
use App\Filament\Support\MiniTable;
use App\Models\DirectPurchase;
use App\Models\DirectPurchaseLine;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductUom;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\TaxGroup;
use App\Models\Uom;
use App\Support\Money;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
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
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Purchasing → Direct purchases: goods that come with a supplier's invoice and no purchase order. Also opened from
 * Stock adjustments, where people look for it. See App\Domain\Purchasing\DirectPurchases.
 */
class DirectPurchaseResource extends Resource
{
    protected static ?string $model = DirectPurchase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Direct purchases';

    protected static ?string $modelLabel = 'direct purchase';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('purchasing.direct') || Erp::canAnywhere('purchasing.direct.approve');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('purchasing.direct');
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'draft' && Erp::canAnywhere('purchasing.direct');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('The invoice')->columns(4)->schema([
                Select::make('supplier_id')->label('Supplier')->required()->searchable()->columnSpan(2)
                    ->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                TextInput::make('invoice_no')->label('Invoice number')->required()->maxLength(60),
                DatePicker::make('invoice_date')->label('Invoice date')->required()->default(now())->maxDate(now()),
                Select::make('warehouse_id')->label('Store')->required()->selectablePlaceholder(false)
                    ->options(fn () => Fields::warehouses('purchasing.direct'))->default(fn () => array_key_first(Fields::warehouses('purchasing.direct'))),
                Toggle::make('prices_include_tax')->label('Prices on the invoice include VAT and levies')->default(false)->inline(false)->columnSpan(2)
                    ->helperText('Most supplier invoices show prices before VAT: leave this off.'),
                TextInput::make('printed_total')->label('Invoice total as printed')->numeric()
                    ->helperText('Checked against the lines before posting, to catch a mistyped line.'),
                FileUpload::make('attachments')->label('Photo or PDF of the invoice (every page)')->multiple()->disk('local')->directory('direct-purchases')->visibility('private')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])->maxSize(10240)->columnSpanFull(),
            ]),
            Section::make('Payment')->columns(4)->schema([
                Radio::make('settlement')->label('How is it paid?')->options(DirectPurchase::SETTLEMENTS)->default('paid')->required()->live()->columnSpanFull()
                    ->descriptions(['paid' => 'The payment is recorded with it.', 'credit' => 'Shows in what you owe the supplier.',
                        'consignment' => 'The goods stay the supplier\'s. Each sale owes them the cost on this invoice; pay from Consignment to pay.']),
                Select::make('method')->label('Paid by')->options(SupplierPayment::METHODS)->default('momo')->required()->visible(fn (Get $get) => $get('settlement') === 'paid'),
                Select::make('account_id')->label('Paid from')->required()->options(fn () => FinanceUi::moneyAccounts())->visible(fn (Get $get) => $get('settlement') === 'paid'),
                TextInput::make('reference')->label('Reference')->maxLength(80)->visible(fn (Get $get) => $get('settlement') === 'paid')
                    ->required(fn (Get $get) => in_array($get('method'), ['bank', 'momo', 'cheque'], true))->helperText('MoMo, transfer or cheque reference.'),
                DatePicker::make('due_on')->label('Due on')->visible(fn (Get $get) => $get('settlement') === 'credit')->helperText('Empty: from the supplier\'s payment terms.'),
            ]),
            Section::make('Products')->schema([
                Repeater::make('lines')->hiddenLabel()->required()->minItems(1)->defaultItems(1)->columns(12)->addActionLabel('Add a line')->schema([
                    Hidden::make('read_as'),
                    TextEntry::make('read_note')->hiddenLabel()->columnSpanFull()->color('warning')->visible(fn (Get $get) => filled($get('read_as')))
                        ->state(fn (Get $get) => 'Read from the invoice as: '.$get('read_as').'. Choose the product, or add it with +.'),
                    self::productField()->columnSpan(5),
                    TextInput::make('qty')->label('Qty')->required()->numeric()->minValue(0.001)->live(onBlur: true)->columnSpan(1),
                    TextInput::make('unit_price')->label('Unit price')->required()->numeric()->minValue(0)->live(onBlur: true)->columnSpan(2),
                    TextInput::make('batch_no')->label('Batch / lot')->maxLength(60)->columnSpan(2),
                    DatePicker::make('expires_on')->label('Expiry')->live()->columnSpan(2),
                    TextInput::make('supplier_sku')->label('Supplier\'s code')->maxLength(60)->columnSpan(2),
                    Select::make('tax_group_id')->label('Tax')->placeholder('As the product')->options(fn () => TaxGroup::orderBy('name')->pluck('name', 'id'))->columnSpan(2),
                    Select::make('sell_uom_id')->label('Sell unit')->placeholder('Unit bought')->live()->columnSpan(3)
                        ->options(fn (Get $get) => self::unitsOf($get('product_uom_id'))),
                    TextInput::make('new_price')->label('New selling price')->numeric()->minValue(0)->live(onBlur: true)->columnSpan(2)
                        ->helperText('Empty: stays as it is.'),
                    TextEntry::make('check')->hiddenLabel()->columnSpan(3)->state(fn (Get $get) => self::lineCheck($get)),
                ]),
            ]),
            Section::make()->columns(2)->schema([
                Select::make('price_list_id')->label('New selling prices go on')->selectablePlaceholder(false)
                    ->options(fn () => PriceList::where('is_active', true)->orderByDesc('is_default')->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => PriceList::where('is_default', true)->value('id')),
                Textarea::make('note')->label('Note')->rows(1),
            ]),
        ]);
    }

    /** The usual product search, plus adding one on the spot with its pack size. */
    private static function productField(): Select
    {
        return Fields::productUnit('product_uom_id', buying: true)->live()
            ->createOptionForm([
                TextInput::make('name')->label('Name')->required()->maxLength(160),
                ProductResource::industryField(),
                Select::make('base_uom_id')->label('Sold in (smallest unit)')->required()->options(fn () => Uom::orderBy('name')->pluck('name', 'id')),
                Select::make('pack_uom_id')->label('Bought in (optional)')->options(fn () => Uom::orderBy('name')->pluck('name', 'id'))
                    ->helperText('e.g. sold in tablets, bought in boxes.'),
                TextInput::make('pack_factor')->label('How many in one')->numeric()->minValue(2)->helperText('e.g. 60 tablets in a box.'),
                Select::make('tax_group_id')->label('Tax')->required()->options(fn () => TaxGroup::orderBy('name')->pluck('name', 'id')),
                Toggle::make('track_batches')->label('Track batches and expiry dates')->default(true),
            ])
            ->createOptionUsing(fn (array $data) => self::addProduct($data))
            ->createOptionAction(fn (Action $action) => $action->visible(fn () => Erp::canAnywhere('catalog.manage'))->modalHeading('Add a product'));
    }

    /** @return string the unit it is bought in */
    private static function addProduct(array $data): string
    {
        $pack = ! empty($data['pack_uom_id']) && (float) ($data['pack_factor'] ?? 0) > 1 && $data['pack_uom_id'] !== $data['base_uom_id'];
        $product = Erp::run(fn ($actor) => app(CatalogService::class)->saveProduct($actor, new Product, ['name' => $data['name'], 'industry_id' => $data['industry_id'] ?? null,
            'base_uom_id' => $data['base_uom_id'], 'tax_group_id' => $data['tax_group_id'], 'track_stock' => true, 'is_active' => true, 'track_batches' => (bool) ($data['track_batches'] ?? false),
            'buying_uom_id' => $pack ? $data['pack_uom_id'] : null, 'units' => $pack ? [['uom_id' => $data['pack_uom_id'], 'factor' => (string) $data['pack_factor'], 'barcodes' => [], 'is_sellable' => true, 'is_purchasable' => true]] : []]));

        return ProductUom::where('product_id', $product->id)->where('uom_id', $pack ? $data['pack_uom_id'] : $data['base_uom_id'])->value('id');
    }

    private static function unitsOf(?string $productUomId): array
    {
        $unit = $productUomId ? ProductUom::find($productUomId) : null;

        return $unit ? ProductUom::with('uom', 'product')->where('product_id', $unit->product_id)->where('is_sellable', true)->get()
            ->mapWithKeys(fn (ProductUom $u) => [$u->id => $u->uom->name.((float) $u->factor != 1 ? ' × '.rtrim(rtrim((string) $u->factor, '0'), '.') : '')])->all() : [];
    }

    /** Beside the line: the new price's margin, and a batch that expires soon. */
    private static function lineCheck(Get $get): ?string
    {
        $out = [];
        $bought = $get('product_uom_id') ? ProductUom::find($get('product_uom_id')) : null;
        if ($bought && is_numeric($get('new_price')) && (float) $get('new_price') > 0 && is_numeric($get('unit_price'))) {
            $sell = $get('sell_uom_id') ? ProductUom::find($get('sell_uom_id')) : $bought;
            $cost = (float) $get('unit_price') * (float) $sell->factor / (float) $bought->factor;
            $out[] = 'Margin '.round(((float) $get('new_price') - $cost) * 100 / (float) $get('new_price')).'%, × '.number_format((float) $get('new_price') / max($cost, 0.0001), 2);
        }
        $months = (int) app(Settings::class)->get('purchasing.short_dated_months', Erp::user()->tenant_id);
        if ($months > 0 && $get('expires_on') && $get('expires_on') < now()->addMonths($months)->toDateString()) {
            $out[] = "Short-dated: under {$months} months";
        }

        return $out ? implode(' · ', $out) : null;
    }

    public static function infolist(Schema $schema): Schema
    {
        $money = fn ($state, DirectPurchase $record) => Money::display((int) $state, $record->currency_code);

        return $schema->components([
            Section::make(fn (DirectPurchase $r) => DirectPurchase::STATUSES[$r->status] ?? $r->status)->columns(4)->schema([
                TextEntry::make('supplier.name')->label('Supplier'),
                TextEntry::make('invoice_no')->label('Invoice'),
                TextEntry::make('invoice_date')->label('Invoice date')->date('j M Y'),
                TextEntry::make('settlement')->label('Payment')->formatStateUsing(fn ($state) => DirectPurchase::SETTLEMENTS[$state] ?? $state),
                TextEntry::make('net_minor')->label('Before tax')->formatStateUsing($money),
                TextEntry::make('tax_minor')->label('VAT and levies')->formatStateUsing($money),
                TextEntry::make('total_minor')->label('Total')->formatStateUsing($money)->weight('bold'),
                TextEntry::make('printed_total_minor')->label('Printed total')->formatStateUsing($money)->placeholder('Not entered'),
                TextEntry::make('warehouse.name')->label('Store'),
                TextEntry::make('method')->label('Paid by')->formatStateUsing(fn ($state) => SupplierPayment::METHODS[$state] ?? $state)->placeholder('—'),
                TextEntry::make('reference')->placeholder('—'),
                TextEntry::make('created_by_name')->label('Entered by'),
                TextEntry::make('goodsReceipt.number')->label('Goods received')->placeholder('—'),
                TextEntry::make('supplierBill.number')->label('Bill')->placeholder('—'),
                TextEntry::make('supplierPayment.number')->label('Payment no.')->placeholder('—'),
                TextEntry::make('posted_by_name')->label('Posted by')->placeholder('—'),
            ]),
            Section::make('Check before posting')->visible(fn (DirectPurchase $r) => $r->isOpen() && app(DirectPurchases::class)->warnings($r) !== [])->schema([
                TextEntry::make('warnings')->hiddenLabel()->color('warning')->bulleted()->state(fn (DirectPurchase $r) => app(DirectPurchases::class)->warnings($r)),
            ]),
            Section::make('Products')->schema([
                TextEntry::make('lines_table')->hiddenLabel()->html()->state(fn (DirectPurchase $r) => MiniTable::html(['Product', 'Qty', 'Unit price', 'Batch', 'Expiry', 'Net', 'Tax', 'New price', 'Margin'],
                    $r->lines()->with('product', 'unit.uom', 'sellUnit.uom')->get()->map(fn (DirectPurchaseLine $l) => [$l->product->name, rtrim(rtrim((string) $l->qty, '0'), '.').' '.$l->unit->uom->code,
                        Money::display($l->unit_price_minor, $r->currency_code), $l->batch_no ?? '—', $l->expires_on?->format('j M Y') ?? '—', Money::display($l->net_minor, $r->currency_code),
                        Money::display($l->tax_minor, $r->currency_code), $l->new_price_minor !== null ? Money::display($l->new_price_minor, $r->currency_code).' / '.$l->sellUnit?->uom->code : '—',
                        ($m = DirectPurchases::margin($l)) !== null ? "{$m}%" : '—'])->all(), [], 'No lines.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('supplier'))
            ->columns([
                TextColumn::make('number')->label('No.')->searchable()->sortable(),
                TextColumn::make('invoice_date')->label('Date')->date('j M Y')->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('invoice_no')->label('Invoice')->searchable(),
                TextColumn::make('settlement')->label('Payment')->formatStateUsing(fn ($state) => DirectPurchase::SETTLEMENTS[$state] ?? $state),
                TextColumn::make('total_minor')->label('Total')->alignEnd()->sortable()->formatStateUsing(fn ($state, DirectPurchase $r) => Money::display((int) $state, $r->currency_code)),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => DirectPurchase::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['posted' => 'success', 'submitted' => 'warning', 'cancelled' => 'gray'][$state] ?? 'info'),
                TextColumn::make('created_by_name')->label('By'),
            ])
            ->filters([SelectFilter::make('status')->options(DirectPurchase::STATUSES), SelectFilter::make('settlement')->label('Payment')->options(DirectPurchase::SETTLEMENTS)])
            ->defaultSort('created_at', 'desc');
    }

    /** @return list<Action> */
    public static function documentActions(): array
    {
        $svc = fn () => app(DirectPurchases::class);

        return [
            Action::make('submit')->label('Send for approval')->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (DirectPurchase $record) => $record->status === 'draft' && $svc()->whyNotPost(Erp::actor(), $record) !== null)
                ->requiresConfirmation()->action(fn (DirectPurchase $record) => Erp::run(fn ($actor) => $svc()->submit($actor, $record), 'Sent for approval')),
            Action::make('post')->label('Post')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (DirectPurchase $record) => $record->isOpen() && $svc()->whyNotPost(Erp::actor(), $record) === null)
                ->requiresConfirmation()->modalHeading('Post this purchase?')
                ->modalDescription(fn (DirectPurchase $record) => ['paid' => 'Receives the goods, enters the bill and records the payment of ', 'credit' => 'Receives the goods and enters the bill for ',
                    'consignment' => 'Receives the goods at no cost, on consignment worth '][$record->settlement].Money::display($record->total_minor, $record->currency_code)
                    .'. New selling prices apply straight away. A posted purchase cannot be edited.')
                ->action(fn (DirectPurchase $record) => Erp::run(fn ($actor) => $svc()->post($actor, $record), 'Purchase posted')),
            Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXMark)->color('gray')
                ->visible(fn (DirectPurchase $record) => $record->isOpen())
                ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(255)])
                ->action(fn (DirectPurchase $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
            Action::make('invoice')->label('The invoice')->icon(Heroicon::OutlinedPaperClip)->color('gray')
                ->visible(fn (DirectPurchase $record) => filled($record->attachments))
                ->schema(fn (DirectPurchase $record) => [Select::make('file')->label('Page')->required()->selectablePlaceholder(false)->default($record->attachments[0] ?? null)
                    ->options(collect($record->attachments)->mapWithKeys(fn ($p, $i) => [$p => 'Page '.($i + 1)])->all())])
                ->action(fn (DirectPurchase $record, array $data) => in_array($data['file'], $record->attachments, true) ? Storage::disk('local')->download($data['file']) : null),
            LabelsAction::make(fn (DirectPurchase $record) => $record->lines()->pluck('product_id')->unique()->values()->all())
                ->visible(fn (DirectPurchase $record) => $record->status === 'posted' && Erp::canAnywhere('catalog.view')),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListDirectPurchases::route('/'), 'create' => CreateDirectPurchase::route('/create'), 'view' => ViewDirectPurchase::route('/{record}'),
            'edit' => EditDirectPurchase::route('/{record}/edit')];
    }
}
