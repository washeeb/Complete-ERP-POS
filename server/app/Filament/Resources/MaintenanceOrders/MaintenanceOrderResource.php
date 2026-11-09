<?php

namespace App\Filament\Resources\MaintenanceOrders;

use App\Domain\Service\Maintenance;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\MaintenanceAssets\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\MaintenanceOrders\Pages\CreateMaintenanceOrder;
use App\Filament\Resources\MaintenanceOrders\Pages\ListMaintenanceOrders;
use App\Filament\Resources\MaintenanceOrders\Pages\ViewMaintenanceOrder;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\IndustryScope;
use App\Filament\Support\ServiceUi;
use App\Models\MaintenanceAsset;
use App\Models\MaintenanceOrder;
use App\Models\Product;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Maintenance → Work orders: planned servicing, breakdown repairs and customer visits, with parts, time and cost. */
class MaintenanceOrderResource extends Resource
{
    protected static ?string $model = MaintenanceOrder::class;

    protected static ?string $slug = 'maintenance-orders';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Maintenance';

    protected static ?string $navigationLabel = 'Work orders';

    protected static ?string $modelLabel = 'work order';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return ServiceUi::can('maintenance.view');
    }

    public static function canCreate(): bool
    {
        return ServiceUi::can('maintenance.work');
    }

    public static function canEdit(Model $record): bool
    {
        return false; // worked from its page
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! ServiceUi::can('maintenance.work')) {
            return null;
        }
        $n = MaintenanceOrder::where('assigned_to', Erp::user()?->id)->whereIn('status', ['open', 'working', 'waiting'])->count();

        return $n ? (string) $n : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return IndustryScope::branches(ServiceUi::maintenance(parent::getEloquentQuery()->with(['asset', 'assignee'])));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                Select::make('asset_id')->label('Equipment')->required()->searchable()->default(fn () => request()->query('asset'))
                    ->options(fn () => ServiceUi::maintenance(MaintenanceAsset::query())->whereNull('archived_at')->where('status', '<>', 'retired')->orderBy('number')->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->number} {$a->name}"])),
                Select::make('kind')->options(MaintenanceOrder::KINDS)->default('breakdown')->required()->selectablePlaceholder(false),
                Select::make('priority')->options(['urgent' => 'Urgent', 'high' => 'High', 'normal' => 'Normal', 'low' => 'Low'])->default('normal')->selectablePlaceholder(false),
                TextInput::make('title')->label('What is to be done')->required()->maxLength(255)->columnSpan(2),
                DatePicker::make('due_on')->label('By'),
                Select::make('assigned_to')->label('Who does it')->placeholder('—')->options(fn () => ServiceUi::people('maintenance.work')),
                Checkbox::make('down')->label('The equipment is not working')->inline(false),
                Textarea::make('details')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('No.')->searchable()->color('gray'),
                TextColumn::make('title')->searchable()->weight('bold')->wrap()->description(fn (MaintenanceOrder $o) => $o->asset?->name),
                TextColumn::make('kind')->formatStateUsing(fn ($state) => MaintenanceOrder::KINDS[$state] ?? $state),
                TextColumn::make('priority')->badge()->color(fn ($state) => match ($state) {
                    'urgent' => 'danger', 'high' => 'warning', default => 'gray'
                })->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => MaintenanceOrder::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'done' => 'success', 'working' => 'info', 'waiting' => 'warning', default => 'gray'
                    }),
                TextColumn::make('due_on')->label('By')->date('j M')->placeholder('—')->color(fn (MaintenanceOrder $o) => $o->isOpen() && $o->due_on?->isPast() ? 'danger' : null),
                TextColumn::make('assignee.name')->label('Who')->placeholder('Nobody yet'),
                TextColumn::make('cost')->label('Cost')->alignEnd()->state(fn (MaintenanceOrder $o) => FinanceUi::money($o->totalMinor())),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (MaintenanceOrder $o) => self::getUrl('view', ['record' => $o]))
            ->filters([
                TernaryFilter::make('open')->label('Open only')->default(true)
                    ->queries(true: fn ($q) => $q->whereIn('status', ['open', 'working', 'waiting']), false: fn ($q) => $q->whereIn('status', ['done', 'cancelled']), blank: fn ($q) => $q),
                TernaryFilter::make('mine')->label('Mine')->queries(true: fn ($q) => $q->where('assigned_to', Erp::user()?->id), false: fn ($q) => $q, blank: fn ($q) => $q),
                SelectFilter::make('kind')->options(MaintenanceOrder::KINDS),
            ])
            ->emptyStateHeading('No work orders')
            ->emptyStateDescription('Plans open work orders by themselves; report a breakdown on the equipment, or from a ticket.');
    }

    /** The buttons on a work order's page. */
    public static function orderActions(): array
    {
        $m = app(Maintenance::class);
        $open = fn (MaintenanceOrder $o) => $o->isOpen() && ServiceUi::can('maintenance.work');
        $parts = fn (bool $return) => [
            Select::make('warehouse_id')->label('Store')->required()->options(fn () => Fields::warehouses(['stock.adjust', 'maintenance.work'])),
            Repeater::make('lines')->label('Parts')->minItems(1)->defaultItems(1)->columns(2)->addActionLabel('Add a part')
                ->schema([Fields::productUnit()->required(), TextInput::make('qty')->label('Quantity')->numeric()->minValue(0.001)->required()]),
        ];

        return [
            Action::make('start')->label('Start')->icon(Heroicon::OutlinedPlay)->visible(fn (MaintenanceOrder $o) => $o->status === 'open' && ServiceUi::can('maintenance.work'))
                ->action(fn (MaintenanceOrder $record) => Erp::run(fn ($actor) => $m->start($actor, $record), 'Started')),
            Action::make('labour')->label('Log time')->icon(Heroicon::OutlinedClock)->color('gray')->visible($open)
                ->schema([TextInput::make('hours')->label('Hours spent')->numeric()->minValue(0.05)->maxValue(24)->required(), TextInput::make('note')->maxLength(120)])
                ->action(fn (MaintenanceOrder $record, array $data) => Erp::run(fn ($actor) => $m->labour($actor, $record, (int) round($data['hours'] * 60), $data['note'] ?? null), 'Time logged')),
            Action::make('parts')->label('Use parts')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')->visible($open)->schema($parts(false))
                ->modalDescription('Parts taken out of a store for this job, at their cost.')
                ->action(fn (MaintenanceOrder $record, array $data) => Erp::run(fn ($actor) => $m->parts($actor, $record, Warehouse::findOrFail($data['warehouse_id']), $data['lines']), 'Parts used')),
            Action::make('partsBack')->label('Parts back')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn (MaintenanceOrder $o) => $open($o) && $o->parts()->exists())->schema($parts(true))
                ->action(fn (MaintenanceOrder $record, array $data) => Erp::run(fn ($actor) => $m->parts($actor, $record, Warehouse::findOrFail($data['warehouse_id']), $data['lines'], true), 'Back in stock')),
            Action::make('wait')->label('Waiting for parts')->icon(Heroicon::OutlinedPause)->color('gray')->visible(fn (MaintenanceOrder $o) => $o->status !== 'waiting' && $open($o))
                ->schema([TextInput::make('why')->label('For what')->required()->maxLength(255)])
                ->action(fn (MaintenanceOrder $record, array $data) => Erp::run(fn ($actor) => $m->waitForParts($actor, $record, $data['why']), 'Marked waiting')),
            Action::make('complete')->label('Done')->icon(Heroicon::OutlinedCheckCircle)->color('success')->visible($open)
                ->fillForm(fn (MaintenanceOrder $o) => ['checklist' => $o->checklist ?? []])
                ->schema(fn (MaintenanceOrder $o) => array_filter([
                    $o->checklist ? Repeater::make('checklist')->label('Checklist')->addable(false)->deletable(false)->reorderable(false)->columns(2)
                        ->schema([TextInput::make('item')->disabled()->dehydrated(), Checkbox::make('done')->label('Done')->inline(false)]) : null,
                    $o->checklist ? Checkbox::make('skip_checklist')->label('Some steps were not done (say why below)') : null,
                    $o->asset?->meter_unit ? TextInput::make('meter_reading')->label('Meter now ('.($o->asset->meter_unit === 'km' ? 'km' : 'hours').')')->numeric()->minValue(0) : null,
                    Textarea::make('outcome')->label('What was done')->required()->rows(3),
                ]))
                ->action(fn (MaintenanceOrder $record, array $data) => Erp::run(fn ($actor) => $m->complete($actor, $record, $data), 'Done')),
            Action::make('invoice')->label('Invoice the customer')->icon(Heroicon::OutlinedDocumentText)->color('success')
                ->visible(fn (MaintenanceOrder $o) => $o->status === 'done' && $o->chargeable && ! $o->invoice_id && ServiceUi::can('maintenance.manage'))
                ->modalDescription('Parts at their price on the customer’s price list; labour as the service you choose.')
                ->schema([
                    TextInput::make('labour')->label('Labour to charge (before tax)')->numeric()->minValue(0)->default(0)->prefix(fn () => FinanceUi::currency()),
                    Select::make('service')->label('Labour shows as')->searchable()
                        ->getSearchResultsUsing(fn (string $search) => Product::where('is_active', true)->where('track_stock', false)->where('name', 'ilike', "%{$search}%")->limit(30)->pluck('name', 'id'))
                        ->getOptionLabelUsing(fn ($value) => Product::find($value)?->name),
                ])
                ->action(function (MaintenanceOrder $record, array $data, $livewire) use ($m) {
                    $invoice = Erp::run(fn ($actor) => $m->invoice($actor, $record, ($data['service'] ?? null) ? Product::find($data['service']) : null, FinanceUi::toMinor($data['labour'] ?? 0)), 'Invoiced');
                    $livewire->redirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
                }),
            Action::make('cancel')->label('Cancel')->icon(Heroicon::OutlinedXCircle)->color('danger')->visible($open)
                ->schema([TextInput::make('why')->label('Why')->required()->maxLength(255)])
                ->action(fn (MaintenanceOrder $record, array $data) => Erp::run(fn ($actor) => $m->cancel($actor, $record, $data['why']), 'Cancelled')),
        ];
    }

    public static function span(int $minutes): string
    {
        return OrdersRelationManager::span($minutes);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceOrders::route('/'),
            'create' => CreateMaintenanceOrder::route('/create'),
            'view' => ViewMaintenanceOrder::route('/{record}'),
        ];
    }
}
