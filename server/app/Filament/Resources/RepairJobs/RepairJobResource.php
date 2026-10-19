<?php

namespace App\Filament\Resources\RepairJobs;

use App\Domain\Electronics\RepairService;
use App\Domain\Modules\ModuleService;
use App\Filament\Resources\RepairJobs\Pages\ListRepairJobs;
use App\Filament\Resources\RepairJobs\Pages\ViewRepairJob;
use App\Filament\Support\Erp;
use App\Filament\Support\Fields;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\GlobalSearch;
use App\Models\Branch;
use App\Models\RepairJob;
use App\Models\Supplier;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use UnitEnum;

/** Repair job cards: devices in for repair, their parts and labour, and where each job stands. */
class RepairJobResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'phone', 'serial', 'customer.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Customer' => $record->customer?->name, 'Status' => $record->status]);
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['customer']);
    }

    protected static ?string $model = RepairJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Electronics';

    protected static ?string $navigationLabel = 'Repairs';

    protected static ?string $modelLabel = 'repair job';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('electronics.repairs') && ModuleService::enabledAnywhere('electronics');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('electronics.repairs');
    }

    public static function canEdit(Model $record): bool
    {
        return false; // progress through the actions; the log keeps the story
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('electronics.repairs');

        return parent::getEloquentQuery()->with('lines')->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('branch_id')->label('Branch')->required()
                ->options(fn () => Branch::when(Erp::branchIds('electronics.repairs') !== null, fn ($q) => $q->whereIn('id', Erp::branchIds('electronics.repairs')))->orderBy('code')->pluck('name', 'id')),
            TextInput::make('customer_name')->label('Customer')->required()->maxLength(120),
            TextInput::make('phone')->tel()->maxLength(20),
            TextInput::make('serial')->label('Serial / IMEI (if known)')->maxLength(80)->helperText('A number we sold shows its warranty.'),
            TextInput::make('device')->label('Device')->maxLength(160)->placeholder('e.g. Samsung A12, black'),
            TextInput::make('accessories')->label('Left with it')->maxLength(255)->placeholder('charger, case…'),
            Textarea::make('fault')->label('What is wrong')->required()->rows(2)->columnSpan(2),
            DatePicker::make('promised_on')->label('Promised for'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('number'),
                TextEntry::make('status')->badge()->formatStateUsing(fn ($state) => RepairJob::STATUSES[$state] ?? $state),
                TextEntry::make('customer_name')->label('Customer')->belowContent(fn (RepairJob $r) => $r->phone),
                TextEntry::make('device')->belowContent(fn (RepairJob $r) => $r->serial),
                TextEntry::make('fault')->columnSpan(2),
                TextEntry::make('in_warranty')->label('Warranty')->formatStateUsing(fn ($state) => $state ? 'In warranty (no charge)' : 'Out of warranty'),
                TextEntry::make('rma_reference')->label('Supplier RMA')->placeholder('—'),
            ]),
            Section::make('Parts and labour')->schema([
                TextEntry::make('charges')->hiddenLabel()->html()->state(fn (RepairJob $r) => $r->lines->isEmpty() ? 'None yet' : '<table style="width:100%">'.$r->lines
                    ->map(fn ($l) => '<tr><td>'.e(Fields::qty($l->qty).' × '.$l->name).'</td><td style="text-align:right">'.e(FinanceUi::money((int) round($l->unit_price_minor * (float) $l->qty))).'</td></tr>')->implode('').'</table>'),
            ]),
            Section::make('Progress')->schema([
                TextEntry::make('progress')->hiddenLabel()->html()->state(fn (RepairJob $r) => '<ul>'.collect($r->log ?? [])->map(fn ($e) => '<li>'
                    .e(Carbon::parse($e['at'])->format('j M H:i')).' · '.e(RepairJob::STATUSES[$e['status']] ?? $e['status']).' · '.e($e['by'])
                    .($e['note'] ? ' — '.e($e['note']) : '').'</li>')->implode('').'</ul>'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('received_at')->label('In')->dateTime('j M H:i')->sortable(),
                TextColumn::make('customer_name')->label('Customer')->searchable()->description(fn (RepairJob $r) => $r->phone),
                TextColumn::make('device')->searchable()->description(fn (RepairJob $r) => $r->serial),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => RepairJob::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => ['ready' => 'success', 'collected' => 'gray', 'at_supplier' => 'warning', 'cancelled' => 'gray'][$state] ?? 'info'),
                IconColumn::make('in_warranty')->label('Warranty')->boolean(),
                TextColumn::make('promised_on')->label('Promised')->date()->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->options(RepairJob::STATUSES)->default(null)])
            ->recordActions([ViewAction::make()]);
    }

    /** @return list<Action> progress actions on the job page */
    public static function jobActions(): array
    {
        $svc = fn () => app(RepairService::class);

        return [
            Action::make('status')->label('Update progress')->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (RepairJob $r) => isset(RepairService::TRANSITIONS[$r->status]))
                ->schema(fn (RepairJob $r) => [
                    Select::make('status')->required()->live()->options(collect(RepairService::TRANSITIONS[$r->status] ?? [])->mapWithKeys(fn ($s) => [$s => RepairJob::STATUSES[$s]])->all()),
                    Select::make('supplier_id')->label('Back to supplier')->options(fn () => Supplier::orderBy('name')->pluck('name', 'id'))
                        ->visible(fn (Get $get) => $get('status') === 'at_supplier')->required(fn (Get $get) => $get('status') === 'at_supplier'),
                    TextInput::make('rma_reference')->label('Supplier RMA number')->maxLength(80)->visible(fn (Get $get) => $get('status') === 'at_supplier'),
                    TextInput::make('note')->maxLength(500),
                ])
                ->action(fn (RepairJob $r, array $data) => Erp::run(fn ($actor) => $svc()->setStatus($actor, $r, $data['status'], $data['note'] ?? null, $data['supplier_id'] ?? null,
                    $data['rma_reference'] ?? null), 'Updated')),
            Action::make('lines')->label('Parts and labour')->icon(Heroicon::OutlinedListBullet)->color('gray')
                ->visible(fn (RepairJob $r) => in_array($r->status, RepairJob::OPEN, true))
                ->fillForm(fn (RepairJob $r) => ['lines' => $r->lines->map(fn ($l) => ['product_uom_id' => $l->product_uom_id, 'qty' => Fields::qty($l->qty)])->all()])
                ->schema([Repeater::make('lines')->columns(3)->defaultItems(0)->schema([
                    Fields::productUnit()->columnSpan(2),
                    TextInput::make('qty')->required()->numeric()->minValue(0.001)->default('1'),
                ])])
                ->action(fn (RepairJob $r, array $data) => Erp::run(fn ($actor) => $svc()->setLines($actor, $r, $data['lines'] ?? []), 'Saved — priced from the price list')),
            Action::make('handOver')->label('Hand over (no charge)')->icon(Heroicon::OutlinedHandRaised)->color('success')
                ->visible(fn (RepairJob $r) => $r->status === 'ready' && $r->lines->sum('unit_price_minor') === 0)
                ->modalDescription('Under warranty, or nothing to charge: any parts used are written off to the repair. Jobs with charges are collected by paying at the till.')
                ->schema(fn (RepairJob $r) => [Select::make('warehouse_id')->label('Parts taken from')->required()
                    ->options(fn () => Warehouse::where('branch_id', $r->branch_id)->orderBy('code')->pluck('name', 'id'))
                    ->default(fn () => Warehouse::where('branch_id', $r->branch_id)->orderBy('code')->value('id'))])
                ->action(fn (RepairJob $r, array $data) => Erp::run(fn ($actor) => $svc()->handOver($actor, $r, Warehouse::findOrFail($data['warehouse_id'])), 'Handed over')),
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListRepairJobs::route('/'), 'view' => ViewRepairJob::route('/{record}')];
    }
}
