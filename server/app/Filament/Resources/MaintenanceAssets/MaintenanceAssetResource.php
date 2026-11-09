<?php

namespace App\Filament\Resources\MaintenanceAssets;

use App\Domain\Service\Maintenance;
use App\Filament\Resources\MaintenanceAssets\Pages\CreateMaintenanceAsset;
use App\Filament\Resources\MaintenanceAssets\Pages\EditMaintenanceAsset;
use App\Filament\Resources\MaintenanceAssets\Pages\ListMaintenanceAssets;
use App\Filament\Resources\MaintenanceAssets\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\MaintenanceAssets\RelationManagers\PlansRelationManager;
use App\Filament\Support\ArchiveUi;
use App\Filament\Support\Erp;
use App\Filament\Support\ServiceUi;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\FixedAsset;
use App\Models\MaintenanceAsset;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
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
use UnitEnum;

/** Maintenance → Equipment: the company's own equipment and customers' equipment, with warranty, meter and plans. */
class MaintenanceAssetResource extends Resource
{
    protected static ?string $model = MaintenanceAsset::class;

    protected static ?string $slug = 'equipment';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Maintenance';

    protected static ?string $navigationLabel = 'Equipment';

    protected static ?string $modelLabel = 'equipment';

    protected static ?string $pluralModelLabel = 'equipment';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return ServiceUi::can('maintenance.view');
    }

    public static function canCreate(): bool
    {
        return ServiceUi::can('maintenance.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return ServiceUi::can('maintenance.view'); // saving needs maintenance.manage (checked on save)
    }

    public static function canDelete(Model $record): bool
    {
        return false; // retired, kept with its history
    }

    public static function getEloquentQuery(): Builder
    {
        return ServiceUi::maintenance(parent::getEloquentQuery()->with(['branch', 'customer']));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255)->columnSpan(2)->placeholder('e.g. Generator 20 kVA, back yard'),
                Select::make('status')->options(MaintenanceAsset::STATUSES)->default('working')->selectablePlaceholder(false)->hiddenOn('create'),
                Radio::make('owner')->label('Whose')->options(['own' => 'Ours', 'customer' => 'A customer’s (sold or serviced by us)'])->default('own')->inline()->live()->columnSpanFull(),
                Select::make('category')->options(MaintenanceAsset::CATEGORIES)->default('other')->selectablePlaceholder(false),
                Select::make('branch_id')->label('Branch')->options(fn () => Branch::where('is_active', true)->orderBy('code')->pluck('name', 'id'))
                    ->required(fn (Get $get) => $get('owner') === 'own')->placeholder('—'),
                TextInput::make('location')->maxLength(255)->placeholder('e.g. Store room, Ward A'),
                Select::make('customer_id')->label('Customer')->searchable()->visible(fn (Get $get) => $get('owner') === 'customer')->required(fn (Get $get) => $get('owner') === 'customer')
                    ->getSearchResultsUsing(fn (string $search) => Customer::where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('phone', 'ilike', "%{$search}%"))->limit(30)->pluck('name', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Customer::find($value)?->name),
                TextInput::make('make')->maxLength(80),
                TextInput::make('model')->maxLength(80),
                TextInput::make('serial_no')->label('Serial number')->maxLength(80),
                Select::make('supplier_id')->label('Bought from')->searchable()->placeholder('—')->options(fn () => Supplier::orderBy('name')->pluck('name', 'id')),
                DatePicker::make('acquired_on')->label('Bought / installed on'),
                DatePicker::make('warranty_until')->label('Warranty until'),
                DatePicker::make('contract_until')->label('Service contract until')->visible(fn (Get $get) => $get('owner') === 'customer'),
                Select::make('fixed_asset_id')->label('In the books as')->placeholder('—')->visible(fn (Get $get) => $get('owner') === 'own')
                    ->options(fn () => FixedAsset::where('status', 'active')->orderBy('number')->get()->mapWithKeys(fn ($f) => [$f->id => "{$f->number} {$f->name}"])),
                Select::make('meter_unit')->label('Meter')->options(['hours' => 'Running hours', 'km' => 'Kilometres'])->placeholder('No meter'),
                Textarea::make('notes')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return ArchiveUi::apply($table
            ->columns([
                TextColumn::make('number')->label('No.')->searchable()->color('gray'),
                TextColumn::make('name')->searchable(['name', 'serial_no', 'make', 'model'])->weight('bold')->wrap()
                    ->description(fn (MaintenanceAsset $a) => $a->owner === 'customer' ? 'Customer: '.$a->customer?->name : trim(($a->branch?->name ?? '').' · '.($a->location ?? ''), ' ·')),
                TextColumn::make('category')->formatStateUsing(fn ($state) => MaintenanceAsset::CATEGORIES[$state] ?? $state),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => MaintenanceAsset::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'working' => 'success', 'down' => 'danger', default => 'gray'
                    }),
                TextColumn::make('warranty_until')->label('Warranty')->date('j M Y')->placeholder('—')
                    ->color(fn (MaintenanceAsset $a) => $a->warranty_until && ! $a->underWarranty() ? 'gray' : null),
                TextColumn::make('meter_reading')->label('Meter')->placeholder('—')->formatStateUsing(fn ($state, MaintenanceAsset $a) => $state === null ? null : rtrim(rtrim((string) $state, '0'), '.').' '.($a->meter_unit === 'km' ? 'km' : 'h')),
                TextColumn::make('next')->label('Next service')->state(fn (MaintenanceAsset $a) => $a->plans()->where('is_active', true)->whereNotNull('next_due_on')->min('next_due_on'))
                    ->date('j M Y')->placeholder('—'),
            ])
            ->defaultSort('number')
            ->filters([
                SelectFilter::make('owner')->label('Whose')->options(['own' => 'Ours', 'customer' => 'Customers’']),
                SelectFilter::make('status')->options(MaintenanceAsset::STATUSES),
                SelectFilter::make('category')->options(MaintenanceAsset::CATEGORIES),
            ])
            ->emptyStateHeading('No equipment yet')
            ->emptyStateDescription('Add what you look after — generators, fridges, vehicles, tills — and customers’ equipment under warranty or contract.'));
    }

    /** "Report a breakdown" and "New meter reading" on the equipment's page. */
    public static function assetActions(): array
    {
        return [
            Action::make('breakdown')->label('Report a breakdown')->icon(Heroicon::OutlinedExclamationTriangle)->color('danger')
                ->visible(fn (MaintenanceAsset $a) => $a->status !== 'retired' && ServiceUi::can('maintenance.work'))
                ->schema([TextInput::make('title')->label('What is wrong')->required()->maxLength(255), Textarea::make('details')->rows(3)])
                ->action(fn (MaintenanceAsset $record, array $data) => Erp::run(fn ($actor) => app(Maintenance::class)->open($actor, $record,
                    ['kind' => $record->owner === 'customer' ? 'visit' : 'breakdown', 'title' => $data['title'], 'details' => $data['details'] ?? null, 'priority' => 'high', 'down' => true]), 'Work order opened')),
            Action::make('meter')->label('New meter reading')->icon(Heroicon::OutlinedClock)->color('gray')
                ->visible(fn (MaintenanceAsset $a) => (bool) $a->meter_unit && ServiceUi::can('maintenance.work'))
                ->schema([TextInput::make('reading')->label('Reading now')->numeric()->minValue(0)->required()])
                ->action(fn (MaintenanceAsset $record, array $data) => Erp::run(fn ($actor) => app(Maintenance::class)->meter($actor, $record, (float) $data['reading']), 'Reading saved')),
        ];
    }

    public static function getRelations(): array
    {
        return [PlansRelationManager::class, OrdersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceAssets::route('/'),
            'create' => CreateMaintenanceAsset::route('/create'),
            'edit' => EditMaintenanceAsset::route('/{record}/edit'),
        ];
    }
}
