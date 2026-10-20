<?php

namespace App\Filament\Resources\Boms;

use App\Domain\Manufacturing\BomService;
use App\Domain\Manufacturing\WorkOrderService;
use App\Domain\Modules\ModuleService;
use App\Filament\Resources\Boms\Pages\CreateBom;
use App\Filament\Resources\Boms\Pages\EditBom;
use App\Filament\Resources\Boms\Pages\ListBoms;
use App\Filament\Resources\Boms\Pages\ViewBom;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\MiniTable;
use App\Models\Bom;
use App\Models\BomLine;
use App\Models\Product;
use App\Models\WorkCentre;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Bills of materials: what a batch of a product uses and gives off, and the operations (routing) that make it. */
class BomResource extends Resource
{
    protected static ?string $model = Bom::class;

    protected static ?string $modelLabel = 'bill of materials';

    protected static ?string $pluralModelLabel = 'bills of materials';

    protected static ?string $recordTitleAttribute = 'code';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Manufacturing';

    protected static ?string $navigationLabel = 'Bills of materials';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('stock.view') && ModuleService::enabledAnywhere('manufacturing');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('manufacturing.setup');
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'draft' && Erp::canAnywhere('manufacturing.setup');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('product')->withCount('lines');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextInput::make('code')->required()->maxLength(30)->helperText('e.g. BREAD-800'),
                Select::make('product_id')->label('Makes')->required()->searchable()->columnSpan(2)
                    ->getSearchResultsUsing(fn (string $search) => Product::where('is_active', true)->where('track_stock', true)
                        ->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"))->orderBy('name')->limit(30)->get()
                        ->mapWithKeys(fn ($p) => [$p->id => "{$p->sku} · {$p->name}"]))
                    ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? "{$p->sku} · {$p->name}" : null),
                TextInput::make('output_qty')->label('One batch makes (base units)')->required()->numeric()->minValue(0.001)->default(1),
                Toggle::make('qc_required')->label('Quality check before stock')->inline(false)
                    ->helperText('Output waits for a quality inspector to pass it.'),
                Textarea::make('note')->label('Method / notes')->rows(2)->columnSpan(3),
            ]),
            Section::make('Materials and by-products (per batch)')
                ->description('Materials are used up; by-products come out alongside the product (e.g. bran from milling) and take part of the cost. Expected loss adds to the material used. Backflushed materials are issued automatically when output is reported; the others are issued by hand.')
                ->schema([
                    Repeater::make('lines')->itemNumbers()->hiddenLabel()->columns(6)->minItems(1)->addActionLabel('Add line')->schema([
                        Select::make('kind')->label('Type')->options(BomLine::KINDS)->default('material')->required()->live(),
                        Fields::productUnit()->columnSpan(2),
                        TextInput::make('qty')->label('Quantity')->required()->numeric()->minValue(0.001),
                        TextInput::make('scrap_pct')->label('Expected loss %')->numeric()->minValue(0)->maxValue(50)->default(0)->visible(fn (Get $get) => $get('kind') !== 'by_product'),
                        Toggle::make('backflush')->label('Backflush')->default(true)->inline(false)->visible(fn (Get $get) => $get('kind') !== 'by_product'),
                    ]),
                ]),
            Section::make('Operations (routing)')
                ->description('The steps, in order, with the work centre that does them. Minutes are per batch. Backflushed operations are charged at these minutes as output is reported; for the others the time is recorded on the work order.')
                ->schema([
                    Repeater::make('operations')->hiddenLabel()->columns(6)->defaultItems(0)->reorderable()->addActionLabel('Add operation')->schema([
                        TextInput::make('name')->required()->maxLength(120)->columnSpan(2),
                        Select::make('work_centre_id')->label('Work centre')->required()->options(fn () => WorkCentre::where('is_active', true)->orderBy('code')->pluck('name', 'id')),
                        TextInput::make('setup_minutes')->label('Set-up min')->numeric()->minValue(0)->default(0),
                        TextInput::make('run_minutes')->label('Run min')->numeric()->minValue(0)->default(0),
                        Toggle::make('backflush')->label('Backflush')->default(true)->inline(false),
                    ]),
                ]),
        ]);
    }

    public static function toFormData(Bom $bom): array
    {
        return ['output_qty' => rtrim(rtrim((string) $bom->output_qty, '0'), '.'),
            'lines' => $bom->lines->map(fn (BomLine $l) => ['kind' => $l->kind, 'product_uom_id' => $l->product_uom_id, 'qty' => rtrim(rtrim((string) $l->qty, '0'), '.'),
                'scrap_pct' => $l->scrap_bp / 100, 'backflush' => $l->backflush])->all(),
            'operations' => array_map(fn ($op) => array_intersect_key($op, array_flip(['name', 'work_centre_id', 'setup_minutes', 'run_minutes', 'backflush'])), $bom->operations ?? [])];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('code')->label('BOM')->formatStateUsing(fn ($state, Bom $r) => "{$state} rev {$r->revision}"),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => Bom::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['active' => 'success', 'archived' => 'gray'][$state] ?? 'warning'),
                TextEntry::make('product.name')->label('Makes')->belowContent(fn (Bom $r) => $r->product?->sku),
                TextEntry::make('output_qty')->label('One batch makes')->formatStateUsing(fn ($state) => Fields::qty($state)),
                TextEntry::make('qc_required')->label('Quality check')->formatStateUsing(fn ($state) => $state ? 'Required before stock' : 'Not required'),
                TextEntry::make('estimate')->label('Estimated cost per unit (today)')->columnSpan(3)->state(function (Bom $r) {
                    $e = app(BomService::class)->estimate($r);

                    return FinanceUi::money($e['per_unit']).' = materials '.FinanceUi::money($e['material']).' + labour and overhead '.FinanceUi::money($e['labour'])
                        .($e['by_product'] ? ' − by-products '.FinanceUi::money($e['by_product']) : '');
                }),
                TextEntry::make('note')->label('Method / notes')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Materials and by-products (per batch)')->schema([
                TextEntry::make('lines_table')->hiddenLabel()->html()->state(fn (Bom $r) => MiniTable::html(['Type', 'SKU', 'Item', 'Quantity', 'Unit', 'Expected loss', 'Issued'],
                    $r->lines()->with('product', 'unit.uom')->get()->map(fn (BomLine $l) => [BomLine::KINDS[$l->kind], $l->product->sku,
                        $l->product->name.(BomService::activeFor($l->product_id) ? ' (made here)' : ''), Qty::format(Qty::toMilli((string) $l->qty)), $l->unit->uom->code,
                        $l->kind === 'material' ? ($l->scrap_bp / 100).' %' : '', $l->kind === 'material' ? ($l->backflush ? 'Backflushed' : 'By hand') : 'With output'])->all(), [3])),
            ]),
            Section::make('Operations (routing)')->schema([
                TextEntry::make('ops_table')->hiddenLabel()->html()->state(fn (Bom $r) => MiniTable::html(['Step', 'Operation', 'Work centre', 'Set-up min', 'Run min', 'Time'],
                    array_map(fn ($op) => [$op['seq'], $op['name'], WorkCentre::whereKey($op['work_centre_id'])->value('name'), $op['setup_minutes'], $op['run_minutes'],
                        $op['backflush'] ? 'Backflushed' : 'Recorded'], $r->operations ?? []), [3, 4], 'No operations: only material cost.')),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')
            ->columns([
                TextColumn::make('code')->searchable()->formatStateUsing(fn ($state, Bom $r) => "{$state} rev {$r->revision}"),
                TextColumn::make('product.name')->label('Makes')->searchable(),
                TextColumn::make('output_qty')->label('Batch')->formatStateUsing(fn ($state) => Fields::qty($state)),
                TextColumn::make('lines_count')->label('Lines'),
                IconColumn::make('qc_required')->label('QC')->boolean(),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => Bom::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['active' => 'success', 'archived' => 'gray'][$state] ?? 'warning'),
            ])
            ->filters([SelectFilter::make('status')->options(Bom::STATUSES)]);
    }

    public static function bomActions(): array
    {
        $svc = fn () => app(BomService::class);

        return [
            Action::make('activate')->label('Put in use')->icon(Heroicon::OutlinedCheckCircle)->color('success')->requiresConfirmation()
                ->modalDescription('This BOM becomes the one used for new work orders; the one in use until now is replaced. A BOM in use cannot be changed.')
                ->visible(fn (Bom $record) => $record->status === 'draft' && Erp::canAnywhere('manufacturing.setup'))
                ->action(fn (Bom $record) => Erp::run(fn ($actor) => $svc()->activate($actor, $record), 'In use')),
            Action::make('revise')->label('New revision')->icon(Heroicon::OutlinedDocumentDuplicate)->color('gray')
                ->visible(fn (Bom $record) => $record->status !== 'draft' && Erp::canAnywhere('manufacturing.setup'))
                ->action(function (Bom $record, $livewire) {
                    $new = Erp::run(fn ($actor) => $svc()->revise($actor, $record));
                    if ($new) {
                        $livewire->redirect(self::getUrl('edit', ['record' => $new]));
                    }
                }),
            Action::make('make')->label('Make…')->icon(Heroicon::OutlinedWrenchScrewdriver)->color('primary')
                ->visible(fn (Bom $record) => $record->status === 'active' && Erp::canAnywhere('manufacturing.produce'))
                ->schema([
                    Select::make('warehouse_id')->label('Warehouse')->required()->options(fn () => Fields::warehouses('manufacturing.produce')),
                    TextInput::make('qty')->label('How many')->required()->numeric()->minValue(0.001),
                    DatePicker::make('due_on')->label('Needed by'),
                ])
                ->action(function (Bom $record, array $data, $livewire) {
                    $wo = Erp::run(fn ($actor) => app(WorkOrderService::class)->create($actor, $data + ['product_id' => $record->product_id, 'bom_id' => $record->id]), 'Work order created');
                    if ($wo) {
                        $livewire->redirect(WorkOrderResource::getUrl('view', ['record' => $wo]));
                    }
                }),
            Action::make('archive')->label('Stop using')->color('danger')->visible(fn (Bom $record) => $record->status !== 'archived' && Erp::canAnywhere('manufacturing.setup'))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (Bom $record, array $data) => Erp::run(fn ($actor) => $svc()->archive($actor, $record, $data['reason']), 'Stopped')),
            EditAction::make()->visible(fn (Bom $record) => $record->status === 'draft'),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListBoms::route('/'), 'create' => CreateBom::route('/create'), 'view' => ViewBom::route('/{record}'), 'edit' => EditBom::route('/{record}/edit')];
    }
}
