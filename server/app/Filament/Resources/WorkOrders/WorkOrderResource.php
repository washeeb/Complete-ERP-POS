<?php

namespace App\Filament\Resources\WorkOrders;

use App\Domain\Manufacturing\WorkOrderService;
use App\Domain\Modules\ModuleService;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\MiniTable;
use App\Models\Bom;
use App\Models\CustomerOrder;
use App\Models\Product;
use App\Models\QcInspection;
use App\Models\WorkCentre;
use App\Models\WorkOrder;
use App\Models\WorkOrderCost;
use App\Support\Exports\Exporter;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
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

/** Work orders: make a quantity of a product, issue materials, report output and time, pass quality checks, finish at actual cost. */
class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Manufacturing';

    protected static ?string $navigationLabel = 'Work orders';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.view') && ModuleService::enabledAnywhere('manufacturing');
    }

    public static function canCreate(): bool
    {
        return false; // "New work order" on the list, "Make…" on a BOM, or from production planning
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

        return parent::getEloquentQuery()->with('product', 'warehouse')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function newOrderForm(): array
    {
        return [
            Select::make('warehouse_id')->label('Warehouse (materials from, output to)')->required()->options(fn () => Fields::warehouses('manufacturing.produce')),
            Select::make('product_id')->label('Product to make')->required()->searchable()
                ->options(fn () => Product::whereIn('id', Bom::where('status', 'active')->select('product_id'))->orderBy('name')->get()->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"])),
            TextInput::make('qty')->label('How many (base units)')->required()->numeric()->minValue(0.001),
            DatePicker::make('due_on')->label('Needed by'),
            Select::make('customer_order_id')->label('For customer order (optional)')->searchable()
                ->getSearchResultsUsing(fn (string $search) => CustomerOrder::whereIn('status', ['confirmed', 'on_hold', 'partially_delivered'])->where('number', 'ilike', "%{$search}%")
                    ->limit(20)->pluck('number', 'id')),
            Textarea::make('note')->rows(2),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        $q = fn ($v) => Fields::qty($v);
        $m = fn ($v) => FinanceUi::money((int) $v);

        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number')->label('Work order'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => WorkOrder::STATUSES[$state] ?? $state)->color(fn ($state) => self::colour($state)),
                TextEntry::make('product.name')->label('Product')->belowContent(fn (WorkOrder $r) => $r->product?->sku),
                TextEntry::make('warehouse.name')->label('Warehouse'),
                TextEntry::make('qty_planned')->label('Planned')->formatStateUsing($q),
                TextEntry::make('qty_done')->label('Made (in stock)')->formatStateUsing($q),
                TextEntry::make('qty_scrapped')->label('Scrapped')->formatStateUsing($q),
                TextEntry::make('qty_awaiting_qc')->label('Waiting for quality check')->formatStateUsing($q)->visible(fn (WorkOrder $r) => $r->qc_required),
                TextEntry::make('due_on')->label('Needed by')->date('D j M Y')->placeholder('—'),
                TextEntry::make('bom_id')->label('Bill of materials')->formatStateUsing(fn ($state) => ($b = Bom::find($state)) ? "{$b->code} rev {$b->revision}" : '—'),
                TextEntry::make('customer_order_id')->label('Customer order')->formatStateUsing(fn ($state) => CustomerOrder::whereKey($state)->value('number'))->placeholder('—'),
                TextEntry::make('parent_work_order_id')->label('Sub-assembly for')->formatStateUsing(fn ($state) => WorkOrder::whereKey($state)->value('number'))->placeholder('—'),
                TextEntry::make('created_by_name')->label('Raised by'),
                TextEntry::make('released_by_name')->label('Released by')->placeholder('—')->belowContent(fn (WorkOrder $r) => $r->released_at?->format('j M Y H:i')),
                TextEntry::make('closed_by_name')->label('Finished / cancelled by')->placeholder('—')->belowContent(fn (WorkOrder $r) => $r->close_reason),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Actual cost')->columns(4)->schema([
                TextEntry::make('material_minor')->label('Materials used')->formatStateUsing($m),
                TextEntry::make('labour_minor')->label('Labour and overhead')->formatStateUsing($m),
                TextEntry::make('by_product_minor')->label('Less by-products')->formatStateUsing($m),
                TextEntry::make('output_minor')->label('Put into stock')->formatStateUsing($m),
                TextEntry::make('wip')->label('Still in work in progress')->state(fn (WorkOrder $r) => $m($r->wipMinor())),
                TextEntry::make('variance_minor')->label('Cost differences at finish')->formatStateUsing($m),
                TextEntry::make('per_unit')->label('Cost per good unit')->state(fn (WorkOrder $r) => Qty::toMilli((string) $r->qty_done) > 0
                    ? $m((int) round(($r->material_minor + $r->labour_minor - $r->by_product_minor) * 1000 / Qty::toMilli((string) $r->qty_done))) : '—'),
            ]),
            Section::make('Materials and by-products')->schema([
                TextEntry::make('materials_table')->hiddenLabel()->html()->state(fn (WorkOrder $r) => MiniTable::html(...self::materialRows($r))),
            ]),
            Section::make('Operations')->schema([
                TextEntry::make('ops_table')->hiddenLabel()->html()->state(fn (WorkOrder $r) => MiniTable::html(...self::operationRows($r))),
            ]),
            Section::make('Labour and cost entries')->collapsed()->schema([
                TextEntry::make('cost_table')->hiddenLabel()->html()->state(fn (WorkOrder $r) => MiniTable::html(['When', 'What', 'Minutes', 'Amount', 'By'],
                    WorkOrderCost::where('work_order_id', $r->id)->orderBy('occurred_at')->get()->map(fn (WorkOrderCost $c) => [$c->occurred_at->format('j M H:i'),
                        $c->kind === 'labour' ? $c->note : 'Left in work in progress at finish', $c->minutes === null ? '' : rtrim(rtrim((string) $c->minutes, '0'), '.'),
                        $m($c->amount_minor), $c->actor_name])->all(), [2, 3])),
            ]),
            Section::make('Quality checks')->visible(fn (WorkOrder $r) => $r->qc_required)->schema([
                TextEntry::make('qc_table')->hiddenLabel()->html()->state(fn (WorkOrder $r) => MiniTable::html(['Check', 'When', 'Passed', 'Failed', 'Checks', 'Note', 'Inspector'],
                    QcInspection::where('work_order_id', $r->id)->orderBy('created_at')->get()->map(fn (QcInspection $c) => [$c->number, $c->created_at->format('j M H:i'),
                        $q($c->qty_passed), $q($c->qty_failed), collect($c->checks)->map(fn ($k) => "{$k['check']}: ".($k['result'] === 'pass' ? '✓' : '✗').($k['value'] ? " {$k['value']}" : ''))->implode('; '),
                        $c->note, $c->inspector_name])->all(), [2, 3], 'No quality checks yet.')),
            ]),
        ]);
    }

    /** @return array{0: list<string>, 1: list<list<string>>, 2: list<int>} */
    public static function materialRows(WorkOrder $r): array
    {
        $rows = $r->materials()->with('product')->get()->map(fn ($l) => [$l->kind === 'material' ? 'Material' : 'By-product', $l->product->sku, $l->product->name,
            Fields::qty($l->planned_qty), Fields::qty($l->issued_qty), $l->kind === 'material' ? ($l->backflush ? 'Backflushed' : 'By hand') : 'With output'])->all();

        return [['Type', 'SKU', 'Item', 'Planned', 'Used / received', 'How'], $rows, [3, 4]];
    }

    /** @return array{0: list<string>, 1: list<list<string>>, 2: list<int>} */
    public static function operationRows(WorkOrder $r): array
    {
        $ops = $r->status === 'draft' ? (Bom::find($r->bom_id)?->operations ?? []) : ($r->operations ?? []);
        $rows = array_map(fn ($op) => [$op['seq'], $op['name'], WorkCentre::whereKey($op['work_centre_id'])->value('name'), $op['planned_minutes'] ?? '—', $op['actual_minutes'] ?? '—',
            $op['backflush'] ? 'Backflushed' : 'Recorded'], $ops);

        return [['Step', 'Operation', 'Work centre', 'Planned min', 'Charged min', 'Time'], $rows, [3, 4]];
    }

    public static function colour(string $status): string
    {
        return ['done' => 'success', 'in_progress' => 'warning', 'released' => 'info', 'cancelled' => 'gray'][$status] ?? 'gray';
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('number', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('product.name')->label('Product')->searchable(),
                TextColumn::make('warehouse.code')->label('Warehouse'),
                TextColumn::make('qty_planned')->label('Planned')->formatStateUsing(fn ($state) => Fields::qty($state)),
                TextColumn::make('qty_done')->label('Made')->formatStateUsing(fn ($state) => Fields::qty($state)),
                TextColumn::make('due_on')->label('Needed by')->date('j M Y')->placeholder('—')->sortable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => WorkOrder::STATUSES[$state] ?? $state)->color(fn ($state) => self::colour($state)),
            ])
            ->filters([
                Filter::make('open')->label('Open only')->default()->query(fn (Builder $query) => $query->whereIn('status', WorkOrder::OPEN)),
                SelectFilter::make('status')->options(WorkOrder::STATUSES),
            ]);
    }

    public static function orderActions(): array
    {
        $svc = fn () => app(WorkOrderService::class);
        $running = fn (WorkOrder $r) => in_array($r->status, ['released', 'in_progress'], true) && Erp::can('manufacturing.produce', $r->warehouse);
        $materials = fn (WorkOrder $r, string $kind = 'material') => $r->materials()->with('product')->where('kind', $kind)->get();

        return [
            Action::make('release')->label('Release')->icon(Heroicon::OutlinedPlayCircle)->color('primary')
                ->visible(fn (WorkOrder $record) => $record->status === 'draft' && Erp::can('manufacturing.produce', $record->warehouse))
                ->schema([Toggle::make('sub_assemblies')->label('Also raise orders for sub-assemblies this warehouse is short of')->default(true)])
                ->action(fn (WorkOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->release($actor, $record, (bool) $data['sub_assemblies']), 'Released')),
            Action::make('output')->label('Report output')->icon(Heroicon::OutlinedCheckBadge)->color('success')->visible($running)
                ->modalDescription('Backflushed materials and operations are charged for everything made (good and scrapped).')
                ->schema([
                    TextInput::make('good')->label('Good units made')->numeric()->minValue(0)->default(0),
                    TextInput::make('scrap')->label('Scrapped units')->numeric()->minValue(0)->default(0),
                    TextInput::make('batch_no')->label('Batch / lot number')->maxLength(60)->helperText('Batch-tracked products; empty = the work order number')
                        ->visible(fn (WorkOrder $record) => $record->product->track_batches && ! $record->qc_required),
                    DatePicker::make('expires_on')->label('Best before / expiry')->visible(fn (WorkOrder $record) => $record->product->track_batches && ! $record->qc_required),
                    Textarea::make('note')->rows(2),
                ])
                ->action(fn (WorkOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->reportOutput($actor, $record, (string) ($data['good'] ?? '0'), (string) ($data['scrap'] ?? '0'),
                    $data['batch_no'] ?? null, $data['expires_on'] ?? null, $data['note'] ?? null), 'Output recorded')),
            Action::make('inspect')->label('Quality check')->icon(Heroicon::OutlinedShieldCheck)->color('warning')
                ->visible(fn (WorkOrder $record) => Qty::toMilli((string) $record->qty_awaiting_qc) > 0 && Erp::can('manufacturing.qc', $record->warehouse))
                ->modalDescription(fn (WorkOrder $record) => Fields::qty($record->qty_awaiting_qc).' waiting. Passed units go into stock; failed units are scrapped.')
                ->schema([
                    TextInput::make('passed')->numeric()->minValue(0)->default(0),
                    TextInput::make('failed')->numeric()->minValue(0)->default(0),
                    Repeater::make('checks')->label('Checks done')->columns(3)->defaultItems(0)->addActionLabel('Add check')->schema([
                        TextInput::make('check')->required()->maxLength(120)->placeholder('e.g. Weight'),
                        Select::make('result')->options(['pass' => 'Pass', 'fail' => 'Fail'])->default('pass')->required(),
                        TextInput::make('value')->label('Reading')->maxLength(60),
                    ]),
                    Textarea::make('note')->label('Note (why units failed)')->rows(2),
                    TextInput::make('batch_no')->label('Batch / lot number')->maxLength(60)->visible(fn (WorkOrder $record) => $record->product->track_batches),
                    DatePicker::make('expires_on')->label('Best before / expiry')->visible(fn (WorkOrder $record) => $record->product->track_batches),
                ])
                ->action(fn (WorkOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->inspect($actor, $record, (string) ($data['passed'] ?? '0'), (string) ($data['failed'] ?? '0'),
                    $data['checks'] ?? [], $data['note'] ?? null, $data['batch_no'] ?? null, $data['expires_on'] ?? null), 'Quality check recorded')),
            Action::make('issue')->label('Issue materials')->icon(Heroicon::OutlinedArrowUpOnSquare)->color('gray')->visible($running)->modalWidth('3xl')
                ->fillForm(fn (WorkOrder $record) => ['lines' => $materials($record)->map(fn ($l) => ['id' => $l->id,
                    'item' => "{$l->product->sku} · {$l->product->name} (planned ".Fields::qty($l->planned_qty).', used '.Fields::qty($l->issued_qty).')', 'qty' => null])->values()->all()])
                ->schema([self::qtyRepeater('Issue now')])
                ->action(fn (WorkOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->issue($actor, $record, self::quantities($data)), 'Issued')),
            Action::make('time')->label('Record time')->icon(Heroicon::OutlinedClock)->color('gray')->visible(fn (WorkOrder $record) => $running($record) && $record->operations)
                ->schema([
                    Select::make('seq')->label('Operation')->required()->options(fn (WorkOrder $record) => collect($record->operations)->mapWithKeys(fn ($op) => [$op['seq'] => "{$op['seq']} · {$op['name']}"])),
                    TextInput::make('minutes')->required()->numeric()->minValue(0.01),
                    TextInput::make('note')->maxLength(200),
                ])
                ->action(fn (WorkOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->recordTime($actor, $record, (int) $data['seq'], $data['minutes'], $data['note'] ?? null), 'Time recorded')),
            Action::make('return')->label('Return materials')->icon(Heroicon::OutlinedArrowUturnLeft)->color('gray')->visible($running)->modalWidth('3xl')
                ->fillForm(fn (WorkOrder $record) => ['lines' => $materials($record)->filter(fn ($l) => Qty::toMilli((string) $l->issued_qty) > 0)->map(fn ($l) => ['id' => $l->id,
                    'item' => "{$l->product->sku} · {$l->product->name} (used ".Fields::qty($l->issued_qty).')', 'qty' => null])->values()->all()])
                ->schema([self::qtyRepeater('Back to stock')])
                ->action(fn (WorkOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->returnMaterials($actor, $record, self::quantities($data)), 'Returned')),
            Action::make('finish')->label('Finish')->icon(Heroicon::OutlinedFlag)->color('success')->visible($running)
                ->modalDescription(fn (WorkOrder $record) => 'Cost still in work in progress ('.FinanceUi::money($record->wipMinor()).') goes to production cost differences. Return unused materials first.')
                ->schema([TextInput::make('reason')->label('Reason, if fewer were made than planned')->maxLength(255)])
                ->action(fn (WorkOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->finish($actor, $record, $data['reason'] ?? null), 'Finished')),
            Action::make('cancel')->color('danger')->visible(fn (WorkOrder $record) => in_array($record->status, ['draft', 'released'], true) && Erp::can('manufacturing.produce', $record->warehouse))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (WorkOrder $record, array $data) => Erp::run(fn ($actor) => $svc()->cancel($actor, $record, $data['reason']), 'Cancelled')),
            Action::make('card')->label('Job card (PDF)')->icon(Heroicon::OutlinedDocumentArrowDown)->color('gray')
                ->action(function (WorkOrder $record) {
                    [$mh, $mr] = self::materialRows($record);
                    [$oh, $or] = self::operationRows($record);
                    $note = sprintf('%s · make %s %s in %s · needed by %s. Made: ______  Scrapped: ______  Operator: ______________  Supervisor: ______________',
                        $record->number, Fields::qty($record->qty_planned), $record->product->name, $record->warehouse->name, $record->due_on?->format('j M Y') ?? '—');

                    return Erp::run(fn ($actor) => app(Exporter::class)->pdf($actor, "Job card {$record->number}", [
                        ['title' => "Job card {$record->number}: materials", 'headings' => $mh, 'rows' => $mr, 'note' => $note],
                        ['title' => 'Operations', 'headings' => $oh, 'rows' => $or],
                    ], [], 'portrait'));
                }),
        ];
    }

    private static function qtyRepeater(string $label): Repeater
    {
        return Repeater::make('lines')->hiddenLabel()->columns(3)->addable(false)->deletable(false)->reorderable(false)->schema([
            Hidden::make('id'),
            TextInput::make('item')->disabled()->dehydrated(false)->columnSpan(2),
            TextInput::make('qty')->label($label)->numeric()->minValue(0),
        ]);
    }

    private static function quantities(array $data): array
    {
        return collect($data['lines'] ?? [])->filter(fn ($l) => filled($l['qty'] ?? null))->mapWithKeys(fn ($l) => [$l['id'] => (string) $l['qty']])->all();
    }

    public static function getPages(): array
    {
        return ['index' => ListWorkOrders::route('/'), 'view' => ViewWorkOrder::route('/{record}')];
    }
}
