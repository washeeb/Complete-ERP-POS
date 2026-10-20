<?php

namespace App\Filament\Resources\StockAdjustments;

use App\Domain\Inventory\ExpiryService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockAdjustmentService;
use App\Filament\Pages\ImportData;
use App\Filament\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\EditStockAdjustment;
use App\Filament\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Filament\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\GlobalSearch;
use App\Models\StockAdjustment;
use App\Support\Exports\Exporter;
use App\Support\Money;
use App\Support\Qty;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
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

/** Stock adjustments: increases, decreases and "set to counted quantity", prepared, approved and posted to the ledger. */
class StockAdjustmentResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'reference'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Reference' => $record->reference, 'Status' => $record->status]);
    }

    protected static ?string $model = StockAdjustment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsVertical;

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?string $navigationLabel = 'Stock adjustments';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.view');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('stock.adjust');
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'draft' && Erp::canAnywhere('stock.adjust');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('stock.view');

        return parent::getEloquentQuery()->with('warehouse')->withCount('lines')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                Select::make('warehouse_id')->label('Warehouse')->required()->options(fn () => Fields::warehouses('stock.adjust'))->disabledOn('edit'),
                Select::make('reason_code')->label('Reason')->required()->options(InventoryService::ADJUSTMENT_REASONS)->default('correction'),
                TextInput::make('reference')->label('Reference (optional)')->maxLength(60)->helperText('e.g. damage report or police report number'),
                Textarea::make('note')->rows(2)->columnSpanFull(),
            ]),
            Section::make('Lines')->description('Increase (+) adds to stock, Decrease (−) takes away, Set to replaces the quantity on hand with what you counted. Nothing changes until the adjustment is posted.')
                ->schema([
                    // One line per row: product, change, quantity, what the store holds, what it will hold, the value, and an
                    // end-of-line Details drop-down for batch, expiry, cost, reason and note (open by itself for batch items).
                    Repeater::make('lines')->hiddenLabel()->itemNumbers()->columns(12)->minItems(1)->addActionLabel('Add line')->schema([
                        Fields::openForBatches(Fields::productUnit()->live())->columnSpan(4),
                        Select::make('mode')->label('Change')->required()->options(StockAdjustment::MODES)->default('remove')->live()->columnSpan(2),
                        TextInput::make('qty')->label(fn (Get $get) => $get('mode') === 'set' ? 'New quantity' : 'Quantity')->required()->numeric()->minValue(0)
                            ->live(onBlur: true),
                        Fields::available('../../warehouse_id', 'batch_no')->columnSpan(2),
                        TextEntry::make('after')->label('After')->state(fn (Get $get) => self::after($get))
                            ->color(fn ($state) => str_starts_with((string) $state, '-') ? 'danger' : null),
                        TextEntry::make('value')->label('Value')->state(fn (Get $get) => ($v = self::lineValue($get)) === null ? '—' : Money::display($v, FinanceUi::currency())),
                        ...Fields::detailsToggle(),
                        Fields::duplicateWarning('batch_no'),
                        TagsInput::make('serials')->label('Serial / IMEI numbers (one per unit)')->columnSpanFull()
                            ->visible(fn (Get $get) => Fields::serialised($get('product_uom_id')) || filled($get('serials'))),
                        Fields::more([
                            TextInput::make('batch_no')->label('Batch no.')->maxLength(60)->live(onBlur: true),
                            DatePicker::make('expires_on')->label('Expiry'),
                            TextInput::make('unit_cost')->label('Unit cost')->numeric()->minValue(0)->visible(fn (Get $get) => $get('mode') === 'add')
                                ->helperText('Empty = average cost'),
                            Select::make('reason_code')->label('Reason (if different)')->options(InventoryService::ADJUSTMENT_REASONS),
                            TextInput::make('note')->maxLength(255)->columnSpan(2),
                        ], 6),
                    ]),
                    // The adjustment's value at average cost, and whether a manager must approve it before posting.
                    TextEntry::make('total_value')->hiddenLabel()->state(function (Get $get) {
                        $sum = 0;
                        foreach ((array) $get('lines') as $line) {
                            $sum += self::lineValue(fn ($k) => $k === '../../warehouse_id' ? $get('warehouse_id') : ($line[$k] ?? null)) ?? 0;
                        }
                        $limit = (int) app(Settings::class)->get('stock.adjustment_approval_minor', Erp::user()->tenant_id);

                        return 'Value at average cost: '.Money::display($sum, FinanceUi::currency())
                            .($sum > $limit ? ' — above '.Money::display($limit, FinanceUi::currency()).': submit it for a manager to approve.' : '');
                    }),
                ]),
        ]);
    }

    public static function toFormData(StockAdjustment $a): array
    {
        return ['lines' => $a->lines()->with('product')->get()->map(fn ($l) => ['product_uom_id' => $l->product_uom_id, 'mode' => $l->mode, 'qty' => rtrim(rtrim((string) $l->qty, '0'), '.'),
            'unit_cost' => $l->unit_cost_minor === null ? null : $l->unit_cost_minor / 100, 'batch_no' => $l->batch_no, 'expires_on' => $l->expires_on?->toDateString(),
            'reason_code' => $l->reason_code, 'note' => $l->note, 'serials' => $l->serials ?? [],
            // Details open where something is filled in, or for a batch-tracked product.
            'more' => filled($l->batch_no) || $l->expires_on || $l->unit_cost_minor !== null || filled($l->reason_code) || filled($l->note) || (bool) $l->product?->track_batches,
        ])->all()];
    }

    /** What the store will hold after the line, in the line's unit ("9 PK"); "—" until the product and quantity are known. */
    private static function after(Get $get): string
    {
        $s = Fields::stockOf($get('product_uom_id'), $get('../../warehouse_id'));
        if (! $s || ! is_numeric($get('qty'))) {
            return '—';
        }
        $q = (float) $get('qty');
        $after = match ($get('mode')) {
            'add' => $s['qty'] + $q, 'set' => $q, default => $s['qty'] - $q
        };

        return Fields::qty((string) round($after, 3), $s['unit']);
    }

    /**
     * The line's value at average cost, in minor units: the change in base units × the average cost (or the cost typed
     * for an increase). Null until the product and quantity are known, or when the product has no cost yet.
     *
     * @param  Get|callable(string): mixed  $get
     */
    private static function lineValue(Get|callable $get): ?int
    {
        $s = Fields::stockOf($get('product_uom_id'), $get('../../warehouse_id'));
        if (! $s || ! is_numeric($get('qty'))) {
            return null;
        }
        $q = (float) $get('qty');
        $change = match ($get('mode')) {
            'add' => $q, 'set' => $q - $s['qty'], default => -$q
        };
        $cost = ($get('mode') === 'add' && is_numeric($get('unit_cost'))) ? (float) $get('unit_cost') * 100 / $s['factor'] : $s['cost'];

        return $cost === null ? null : (int) round(abs($change) * $s['factor'] * $cost);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Adjustment'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => StockAdjustment::STATUSES[$state] ?? $state),
                TextEntry::make('warehouse.name')->label('Warehouse'),
                TextEntry::make('reason_code')->label('Reason')->formatStateUsing(fn ($state) => InventoryService::ADJUSTMENT_REASONS[$state] ?? $state),
                TextEntry::make('reference')->placeholder('—'),
                TextEntry::make('created_by_name')->label('Prepared by'),
                TextEntry::make('submitted_by_name')->asPerson('submitted_by')->label('Submitted by')->placeholder('—'),
                TextEntry::make('posted_by_name')->label('Approved and posted by')->placeholder('—')->belowContent(fn (StockAdjustment $r) => $r->posted_at?->format('j M Y H:i')),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Disposal')->columns(4)->visible(fn (StockAdjustment $r) => $r->kind === 'disposal')
                ->description('Removed from stock once approved by someone other than the person who prepared it. Keep the packs aside, marked "not for sale", until they are destroyed.')
                ->schema([
                    TextEntry::make('disposal_method')->label('Method')->placeholder('—')->formatStateUsing(fn ($state) => ExpiryService::DISPOSAL_METHODS[$state] ?? $state),
                    TextEntry::make('disposed_on')->label('Disposed of on')->date('j M Y')->placeholder('—'),
                    TextEntry::make('witness_name')->label('Witness')->placeholder('—'),
                    TextEntry::make('certificate_path')->label('Certificate')->placeholder('none')->formatStateUsing(fn () => 'Open')
                        ->url(fn (StockAdjustment $r) => $r->certificate_path ? route('disposal.certificate', $r) : null, true),
                ]),
            Section::make('Lines')->schema([
                TextEntry::make('lines_table')->hiddenLabel()->html()->state(fn (StockAdjustment $r) => new HtmlString(self::linesHtml($r))),
            ]),
        ]);
    }

    /** @return array{headings: list<string>, rows: list<list<string>>, total: int} */
    public static function lineRows(StockAdjustment $r): array
    {
        $currency = FinanceUi::currency();
        $rows = [];
        $total = 0;
        if ($r->status === 'posted') {
            foreach ($r->lines()->with('product', 'unit.uom')->get() as $l) {
                $rows[] = [$l->line_no, $l->product->sku, $l->product->name, StockAdjustment::MODES[$l->mode], self::q($l->qty).' '.$l->unit->uom->code,
                    self::q($l->on_hand_before), self::q($l->change_qty), self::q(Qty::format(Qty::toMilli((string) $l->on_hand_before) + Qty::toMilli((string) $l->change_qty))),
                    $l->batch_no ?? '', InventoryService::ADJUSTMENT_REASONS[$l->reason_code ?? $r->reason_code] ?? '', $l->note ?? ''];
            }
            $total = $r->value_minor;
        } else {
            $p = app(StockAdjustmentService::class)->preview($r);
            foreach ($p['lines'] as $x) {
                $l = $x['line'];
                $rows[] = [$l->line_no, $l->product->sku, $l->product->name, StockAdjustment::MODES[$l->mode], self::q($l->qty).' '.$l->unit->uom->code,
                    Qty::format($x['before']), Qty::format($x['change']), Qty::format($x['after']), $l->batch_no ?? '',
                    InventoryService::ADJUSTMENT_REASONS[$l->reason_code ?? $r->reason_code] ?? '', $l->note ?? ''];
            }
            $total = $p['value'];
        }

        return ['headings' => ['#', 'SKU', 'Product', 'Change', 'Quantity', 'On hand before', 'Change (base units)', 'On hand after', 'Batch', 'Reason', 'Note'],
            'rows' => $rows, 'total' => $total, 'currency' => $currency];
    }

    private static function linesHtml(StockAdjustment $r): string
    {
        $t = self::lineRows($r);
        $head = '<tr>'.collect($t['headings'])->map(fn ($h) => '<th style="text-align:left;padding:4px 6px">'.e($h).'</th>')->implode('').'</tr>';
        $body = collect($t['rows'])->map(fn ($row) => '<tr>'.collect($row)->map(fn ($v) => '<td style="padding:4px 6px;border-top:1px solid color-mix(in srgb, currentColor 6%, transparent)">'.e($v).'</td>')->implode('').'</tr>')->implode('');
        $note = $r->status === 'posted' ? 'Value of the change at average cost: ' : 'If posted now (against stock on hand now), value at average cost: ';

        return '<table style="width:100%;font-size:.85rem;border-collapse:collapse">'.$head.$body.'</table><p style="margin-top:8px;font-size:.85rem">'.e($note.Money::format($t['total'], $t['currency'])).'</p>';
    }

    private static function q($v): string
    {
        return $v === null ? '' : Qty::format(Qty::toMilli((string) $v));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('created_at')->label('Date')->date('j M Y'),
                TextColumn::make('warehouse.code')->label('Warehouse'),
                TextColumn::make('reason_code')->label('Reason')->formatStateUsing(fn ($state) => InventoryService::ADJUSTMENT_REASONS[$state] ?? $state)
                    ->description(fn (StockAdjustment $r) => $r->kind === 'disposal' ? 'disposal' : null),
                TextColumn::make('reference')->placeholder('—')->searchable(),
                TextColumn::make('lines_count')->label('Lines'),
                TextColumn::make('value_minor')->label('Value')->formatStateUsing(fn ($state, StockAdjustment $r) => $r->status === 'posted' ? FinanceUi::money((int) $state) : '—'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => StockAdjustment::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['posted' => 'success', 'submitted' => 'warning', 'cancelled' => 'gray'][$state] ?? 'info'),
                TextColumn::make('created_by_name')->label('Prepared by')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(StockAdjustment::STATUSES), SelectFilter::make('kind')->label('Type')->options(StockAdjustment::KINDS), SelectFilter::make('reason_code')->label('Reason')->options(InventoryService::ADJUSTMENT_REASONS)]);
    }

    /** Submit, approve and post, send back, cancel, add lines from a file, and the adjustment voucher (CSV / PDF). */
    public static function adjustmentActions(): array
    {
        $svc = fn () => app(StockAdjustmentService::class);

        return [
            Action::make('importLines')->label('Add lines from CSV')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
                ->visible(fn (StockAdjustment $record) => $record->status === 'draft')
                ->url(fn (StockAdjustment $record) => ImportData::getUrl(['type' => 'stock_adjustment', 'context' => $record->id])),
            Action::make('submit')->label('Submit for approval')->icon(Heroicon::OutlinedPaperAirplane)->color('warning')->requiresConfirmation()
                ->visible(fn (StockAdjustment $record) => $record->status === 'draft')
                ->action(fn (StockAdjustment $record) => Erp::run(fn ($actor) => $svc()->submit($actor, $record), 'Submitted for approval')),
            Action::make('post')->label(fn (StockAdjustment $record) => $record->status === 'submitted' ? 'Approve and post' : 'Post')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                ->visible(fn (StockAdjustment $record) => in_array($record->status, ['draft', 'submitted'], true))
                ->requiresConfirmation()->modalDescription('The changes are booked in the stock ledger now, against the stock on hand at this moment. A posted adjustment cannot be changed — correct it with another adjustment.')
                ->action(fn (StockAdjustment $record) => Erp::run(fn ($actor) => $svc()->post($actor, $record), 'Posted to stock')),
            Action::make('reopen')->label('Send back to draft')->color('gray')->visible(fn (StockAdjustment $record) => $record->status === 'submitted' && $record->kind !== 'disposal')
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (StockAdjustment $record, array $data) => Erp::run(fn ($actor) => $svc()->reopen($actor, $record, $data['reason']), 'Back to draft')),
            Action::make('cancel')->label('Cancel')->color('danger')->visible(fn (StockAdjustment $record) => in_array($record->status, ['draft', 'submitted'], true))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (StockAdjustment $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
            Action::make('disposalRecord')->label('Disposal record (print)')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->visible(fn (StockAdjustment $record) => $record->kind === 'disposal')
                ->url(fn (StockAdjustment $record) => route('print.disposal', $record), true),
            ActionGroup::make([
                Action::make('voucherPdf')->label('Adjustment voucher (PDF)')->icon(Heroicon::OutlinedDocumentArrowDown)->action(fn (StockAdjustment $record) => self::voucher($record, 'pdf')),
                Action::make('voucherCsv')->label('Lines (CSV)')->icon(Heroicon::OutlinedTableCells)->action(fn (StockAdjustment $record) => self::voucher($record, 'csv')),
            ])->label('Export')->icon(Heroicon::OutlinedArrowDownTray)->button()->color('gray'),
        ];
    }

    public static function voucher(StockAdjustment $r, string $format)
    {
        $t = self::lineRows($r);
        $sheet = ['title' => "Stock adjustment {$r->number}", 'headings' => $t['headings'], 'rows' => $t['rows'],
            'note' => sprintf('%s · %s · Reason: %s%s · Status: %s · Prepared by %s%s · Value at average cost: %s. Signed (approved by): ______________________',
                $r->warehouse->code, $r->warehouse->name, InventoryService::ADJUSTMENT_REASONS[$r->reason_code] ?? $r->reason_code, $r->reference ? " ({$r->reference})" : '',
                StockAdjustment::STATUSES[$r->status], $r->created_by_name, $r->posted_by_name ? ", posted by {$r->posted_by_name}" : '', Money::format($t['total'], $t['currency']))];

        return Erp::run(fn ($actor) => $format === 'pdf' ? app(Exporter::class)->pdf($actor, "Stock adjustment {$r->number}", [$sheet])
            : app(Exporter::class)->csv($actor, "Stock adjustment {$r->number}", [$sheet]));
    }

    public static function getPages(): array
    {
        return ['index' => ListStockAdjustments::route('/'), 'create' => CreateStockAdjustment::route('/create'),
            'view' => ViewStockAdjustment::route('/{record}'), 'edit' => EditStockAdjustment::route('/{record}/edit')];
    }
}
