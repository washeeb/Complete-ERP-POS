<?php

namespace App\Filament\Resources\SalesRoutes;

use App\Domain\Distribution\RouteService;
use App\Domain\Modules\ModuleService;
use App\Filament\Resources\SalesRoutes\Pages\ListSalesRoutes;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\SalesRoute;
use App\Models\User;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Sales routes: the customers a van visits, in order, and the days it runs. */
class SalesRouteResource extends Resource
{
    protected static ?string $model = SalesRoute::class;

    protected static ?string $slug = 'routes';

    protected static ?string $modelLabel = 'route';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Distribution';

    protected static ?string $navigationLabel = 'Routes';

    protected static ?int $navigationSort = 3;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('customers.view') && ModuleService::enabledAnywhere('distribution');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('distribution.routes');
    }

    public static function canEdit(Model $record): bool
    {
        return Erp::canAnywhere('distribution.routes');
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('code')->required()->maxLength(20),
            TextInput::make('name')->required()->maxLength(255)->columnSpan(2),
            Select::make('branch_id')->label('Branch')->required()->live()->options(fn () => Branch::orderBy('code')->pluck('name', 'id')),
            Select::make('van_warehouse_id')->label('Van')->options(fn (Get $get) => Warehouse::where('is_van', true)->when($get('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))->orderBy('code')->pluck('name', 'id')),
            Select::make('rep_user_id')->label('Rep')->searchable()->options(fn () => User::where('is_active', true)->orderBy('name')->pluck('name', 'id')),
            CheckboxList::make('days')->label('Runs on')->options(SalesRoute::DAYS)->columns(7)->columnSpanFull(),
            Toggle::make('is_active')->label('In use')->default(true),
            Repeater::make('stops')->label('Customers, in visiting order')->simple(
                Select::make('customer_id')->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Customer::where('is_active', true)->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"))
                        ->orderBy('name')->limit(30)->get()->mapWithKeys(fn ($c) => [$c->id => trim("{$c->name} · {$c->code}")]))
                    ->getOptionLabelUsing(fn ($value) => Customer::whereKey($value)->value('name')),
            )->reorderable()->addActionLabel('Add customer')->defaultItems(0)->columnSpanFull(),
        ]);
    }

    public static function toFormData(SalesRoute $r): array
    {
        return ['days' => array_map('strval', $r->days ?? []), 'stops' => $r->stops()->pluck('customer_id')->all()];
    }

    public static function toDomain(array $data): array
    {
        return array_diff_key($data, ['stops' => 1]) + ['customer_ids' => array_values($data['stops'] ?? []), 'days' => array_map('intval', $data['days'] ?? [])];
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('days')->label('Runs on')->formatStateUsing(fn (SalesRoute $record) => collect($record->days ?? [])->map(fn ($d) => SalesRoute::DAYS[$d] ?? $d)->implode(' ')),
                TextColumn::make('van_warehouse_id')->label('Van')->formatStateUsing(fn ($state) => Warehouse::whereKey($state)->value('code'))->placeholder('—'),
                TextColumn::make('rep_user_id')->label('Rep')->formatStateUsing(fn ($state) => User::whereKey($state)->value('name'))->placeholder('—'),
                TextColumn::make('stops_count')->label('Customers')->counts('stops'),
                IconColumn::make('is_active')->label('In use')->boolean(),
            ])
            ->recordActions([EditAction::make()->mutateRecordDataUsing(fn (array $data, SalesRoute $record) => $data + self::toFormData($record))
                ->using(fn (SalesRoute $record, array $data) => Erp::run(fn ($actor) => app(RouteService::class)->save($actor, $record, self::toDomain($data))))]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSalesRoutes::route('/')];
    }
}
