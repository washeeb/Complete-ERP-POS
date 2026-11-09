<?php

namespace App\Filament\Resources\PurchaseAgreements;

use App\Domain\Purchasing\AgreementService;
use App\Filament\Resources\PurchaseAgreements\Pages\CreatePurchaseAgreement;
use App\Filament\Resources\PurchaseAgreements\Pages\EditPurchaseAgreement;
use App\Filament\Resources\PurchaseAgreements\Pages\ListPurchaseAgreements;
use App\Filament\Resources\PurchaseAgreements\Pages\ViewPurchaseAgreement;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\IndustryScope;
use App\Filament\Support\MiniTable;
use App\Models\PurchaseAgreement;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Qty;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use UnitEnum;

/** Contracts (agreed prices for a period) and blanket orders (agreed prices with quantities or a value to draw down). */
class PurchaseAgreementResource extends Resource
{
    /** Follows the industry in view (owner 2026-11-09: every industry independent). */
    public static function getEloquentQuery(): Builder
    {
        return IndustryScope::owner(parent::getEloquentQuery(), 'supplier');
    }

    protected static ?string $model = PurchaseAgreement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Contracts and blanket orders';

    protected static ?string $modelLabel = 'agreement';

    protected static ?string $pluralModelLabel = 'contracts and blanket orders';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('purchasing.manage') || Erp::canAnywhere('purchasing.approve') || Erp::canAnywhere('purchasing.view');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('purchasing.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return $record->status === 'draft' && Erp::canAnywhere('purchasing.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return array<string, mixed> the agreement as the form edits it */
    public static function toFormData(PurchaseAgreement $a): array
    {
        return ['value_cap' => $a->value_cap_minor === null ? null : Money::format($a->value_cap_minor, $a->currency_code),
            'lines' => $a->lines->map(fn ($l) => ['kind' => $l->product_uom_id ? 'stock' : 'service', 'product_uom_id' => $l->product_uom_id, 'account_id' => $l->account_id,
                'description' => $l->product_uom_id ? null : $l->name, 'uom_code' => $l->uom_code, 'unit_cost' => Money::format($l->unit_cost_minor, $a->currency_code),
                'max_qty' => $l->max_qty === null ? null : Fields::qty($l->max_qty), 'note' => $l->note])->all()];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                Select::make('supplier_id')->label('Supplier')->required()->searchable()->options(fn () => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
                Select::make('kind')->label('Kind')->required()->live()->default('contract')->options(PurchaseAgreement::KINDS)->selectablePlaceholder(false),
                TextInput::make('title')->required()->maxLength(160)->placeholder('e.g. Antimalarials prices 2027'),
                DatePicker::make('starts_on')->label('From')->required()->default(today()),
                DatePicker::make('ends_on')->label('Until')->required()->default(today()->addYear()->subDay()),
                TextInput::make('value_cap')->label('Most that may be ordered in total (before VAT, optional)')->numeric()->minValue(0.01),
                Textarea::make('terms')->label('Terms (delivery, credit, returns…)')->rows(2)->maxLength(2000)->columnSpanFull(),
                FileUpload::make('file')->label('The signed agreement (PDF or photo, optional)')->disk('local')->directory('agreements')->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/*'])->maxSize(10240)->storeFileNamesIn('file_name')->columnSpanFull(),
                Hidden::make('file_name'),
            ]),
            Section::make('Items and agreed prices')->schema([
                Repeater::make('lines')->hiddenLabel()->itemNumbers()->columns(6)->minItems(1)->addActionLabel('Add item')->schema([
                    ...Fields::spendLine(),
                    TextInput::make('unit_cost')->label('Agreed price per unit (before VAT)')->required()->numeric()->minValue(0.0001),
                    TextInput::make('max_qty')->label('Most that may be ordered (optional)')->numeric()->minValue(0.001)
                        ->visible(fn (Get $get) => $get('../../kind') === 'blanket'),
                    TextInput::make('note')->maxLength(255),
                ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->state(fn (PurchaseAgreement $record) => $record->statusLabel())
                    ->color(fn (PurchaseAgreement $record) => $record->inForce() ? 'success' : ($record->status === 'draft' ? 'warning' : 'gray')),
                TextEntry::make('supplier.name')->label('Supplier'),
                TextEntry::make('kind')->formatStateUsing(fn ($state) => PurchaseAgreement::KINDS[$state] ?? $state),
                TextEntry::make('title')->columnSpan(2),
                TextEntry::make('starts_on')->label('From')->date(),
                TextEntry::make('ends_on')->label('Until')->date(),
                TextEntry::make('used')->label('Ordered so far')->state(function (PurchaseAgreement $record) {
                    $v = app(AgreementService::class)->usage($record)['value'];

                    return Money::display($v, $record->currency_code).($record->value_cap_minor ? ' of '.Money::display($record->value_cap_minor, $record->currency_code) : '');
                }),
                TextEntry::make('created_by_name')->label('Made by'),
                TextEntry::make('approved_by_name')->label('Put in force by')->placeholder('—'),
                TextEntry::make('closed_reason')->label('Closed because')->visible(fn (PurchaseAgreement $record) => $record->status === 'closed'),
                TextEntry::make('file_name')->label('Signed agreement')->placeholder('—')
                    ->url(fn (PurchaseAgreement $record) => $record->file_path ? route('agreement.file', $record) : null, shouldOpenInNewTab: true),
                TextEntry::make('terms')->placeholder('—')->columnSpanFull(),
            ]),
            Section::make('Items')->schema([
                TextEntry::make('items')->hiddenLabel()->state(fn (PurchaseAgreement $record) => self::linesTable($record)),
            ]),
            Section::make('Orders made against it')->collapsed()->schema([
                TextEntry::make('orders')->hiddenLabel()->state(fn (PurchaseAgreement $record) => MiniTable::html(['Order', 'Status', 'Total'],
                    PurchaseOrder::whereIn('id', DB::table('purchase_order_lines')->whereIn('agreement_line_id', $record->lines->pluck('id'))->select('purchase_order_id'))
                        ->orderByDesc('created_at')->get()->map(fn (PurchaseOrder $p) => [$p->number, PurchaseOrder::STATUSES[$p->status] ?? $p->status,
                            Money::display((int) $p->total_minor, $p->currency_code)])->all(), [2], 'None yet.')),
            ]),
        ]);
    }

    private static function linesTable(PurchaseAgreement $a): HtmlString
    {
        $used = app(AgreementService::class)->usage($a)['lines'];

        return MiniTable::html(['#', 'Item', 'Agreed price', 'Ordered', 'Left'], $a->lines->map(fn ($l) => [$l->line_no, $l->name.($l->note ? " ({$l->note})" : ''),
            Money::display($l->unit_cost_minor, $a->currency_code).' / '.$l->uom_code, Fields::qty(Qty::format($used[$l->id] ?? 0), $l->uom_code),
            $l->max_qty === null ? 'No limit' : Fields::qty(Qty::format(max(0, Qty::toMilli((string) $l->max_qty) - ($used[$l->id] ?? 0))), $l->uom_code)])->all(), [2, 3, 4]);
    }

    public static function workflowActions(): array
    {
        $svc = fn () => app(AgreementService::class);

        return [
            Action::make('activate')->label('Put in force')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (PurchaseAgreement $record) => $record->status === 'draft' && Erp::canAnywhere('purchasing.approve'))
                ->requiresConfirmation()->modalDescription('From its start date, orders to this supplier take these prices and never pay more. Someone other than who made it puts it in force.')
                ->action(fn (PurchaseAgreement $record) => Erp::run(fn ($actor) => $svc()->activate($actor, $record), 'In force')),
            Action::make('release')->label('Order against it')->icon(Heroicon::OutlinedShoppingCart)->color('primary')
                ->visible(fn (PurchaseAgreement $record) => $record->inForce() && Erp::canAnywhere('purchasing.manage'))
                ->modalDescription('A draft purchase order at the agreed prices. Check it and submit it for approval as usual.')
                ->fillForm(fn (PurchaseAgreement $record) => ['lines' => $record->lines->map(fn ($l) => ['id' => $l->id,
                    'item' => "{$l->name} · ".Money::display($l->unit_cost_minor, $record->currency_code)." / {$l->uom_code}", 'qty' => null])->all()])
                ->schema([
                    Select::make('warehouse_id')->label('Deliver to')->required()->options(fn () => Fields::warehouses('purchasing.manage')),
                    DatePicker::make('expected_on')->label('Expected delivery'),
                    Repeater::make('lines')->addable(false)->deletable(false)->reorderable(false)->columns(3)->schema([
                        TextInput::make('item')->disabled()->dehydrated(false)->columnSpan(2),
                        TextInput::make('qty')->label('Quantity now')->numeric()->minValue(0),
                        Hidden::make('id'),
                    ]),
                ])
                ->action(function (PurchaseAgreement $record, array $data, Action $action) use ($svc) {
                    $po = Erp::run(fn ($actor) => $svc()->release($actor, $record, Warehouse::findOrFail($data['warehouse_id']),
                        collect($data['lines'])->mapWithKeys(fn ($l) => [$l['id'] => (string) ($l['qty'] ?? '')])->all(), $data['expected_on'] ?? null), 'Draft purchase order made');
                    $action->redirect(PurchaseOrderResource::getUrl('edit', ['record' => $po]));
                }),
            Action::make('close')->label('Close')->icon(Heroicon::OutlinedArchiveBoxXMark)->color('gray')
                ->visible(fn (PurchaseAgreement $record) => $record->status !== 'closed' && Erp::canAnywhere('purchasing.manage'))
                ->schema([TextInput::make('reason')->required()->maxLength(255)])
                ->action(fn (PurchaseAgreement $record, array $data) => Erp::run(fn ($actor) => $svc()->close($actor, $record, $data['reason']), 'Closed')),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),
                TextColumn::make('supplier.name')->label('Supplier')->searchable(),
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('kind')->formatStateUsing(fn ($state) => $state === 'blanket' ? 'Blanket order' : 'Contract'),
                TextColumn::make('ends_on')->label('Until')->date()->sortable(),
                TextColumn::make('status')->badge()->state(fn (PurchaseAgreement $r) => $r->statusLabel())
                    ->color(fn (PurchaseAgreement $r) => $r->inForce() ? 'success' : ($r->status === 'draft' ? 'warning' : 'gray')),
            ])
            ->filters([SelectFilter::make('status')->options(PurchaseAgreement::STATUSES), SelectFilter::make('supplier_id')->label('Supplier')->relationship('supplier', 'name')])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->defaultSort('ends_on')
            ->emptyStateHeading('No contracts or blanket orders')
            ->emptyStateDescription('Agree prices with a supplier for a period; orders then take those prices and count against any limits.');
    }

    public static function getPages(): array
    {
        return ['index' => ListPurchaseAgreements::route('/'), 'create' => CreatePurchaseAgreement::route('/create'),
            'view' => ViewPurchaseAgreement::route('/{record}'), 'edit' => EditPurchaseAgreement::route('/{record}/edit')];
    }
}
