<?php

namespace App\Filament\Resources\IotSensors;

use App\Domain\Iot\Sensors;
use App\Filament\Resources\IotSensors\Pages\CreateIotSensor;
use App\Filament\Resources\IotSensors\Pages\EditIotSensor;
use App\Filament\Resources\IotSensors\Pages\ListIotSensors;
use App\Filament\Resources\IotSensors\RelationManagers\AlarmsRelationManager;
use App\Filament\Resources\IotSensors\RelationManagers\ChecksRelationManager;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\IotConnection;
use App\Models\IotSensor;
use App\Models\MaintenanceAsset;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
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

/** Sensors → Sensors: each fridge, cold room, freezer, room, power line or door watched, with its range and state now. */
class IotSensorResource extends Resource
{
    protected static ?string $model = IotSensor::class;

    protected static ?string $slug = 'sensors';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Sensors';

    protected static ?string $navigationLabel = 'Sensors';

    protected static ?string $modelLabel = 'sensor';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function allowed(string $permission): bool
    {
        return Sensors::enabled() && Erp::canAnywhere($permission);
    }

    public static function canViewAny(): bool
    {
        return self::allowed('iot.view');
    }

    public static function canCreate(): bool
    {
        return self::allowed('iot.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return self::allowed('iot.view'); // the page shows readings and checks; saving needs iot.manage
    }

    public static function canDelete(Model $record): bool
    {
        return false; // switched off, kept with its log
    }

    public static function getNavigationBadge(): ?string
    {
        if (! self::allowed('iot.view')) {
            return null;
        }
        $n = self::scoped(IotSensor::query())->where('status', 'alarm')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function scoped(Builder $q): Builder
    {
        $b = Erp::branchIds('iot.view');

        return $b === null ? $q : $q->whereIn('branch_id', $b);
    }

    public static function getEloquentQuery(): Builder
    {
        return self::scoped(parent::getEloquentQuery()->with(['branch']));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make('Now')->columns(4)->hiddenOn('create')->schema(fn (?IotSensor $record) => $record ? self::now($record) : []),
            Section::make()->columns(3)->schema([
                TextInput::make('name')->required()->maxLength(255)->columnSpan(2)->placeholder('e.g. Vaccine fridge, dispensary'),
                Toggle::make('is_active')->label('In use')->default(true)->inline(false)->hiddenOn('create'),
                Select::make('kind')->label('It watches')->options(collect(IotSensor::KINDS)->map(fn ($k) => $k[0]))->default('fridge')->required()->live()->selectablePlaceholder(false)
                    ->afterStateUpdated(function ($state, callable $set) {
                        [, $min, $max, $minH, $maxH] = IotSensor::KINDS[$state] ?? IotSensor::KINDS['fridge'];
                        $set('min_temp', $min);
                        $set('max_temp', $max);
                        $set('min_humidity', $minH);
                        $set('max_humidity', $maxH);
                    }),
                Select::make('branch_id')->label('Branch')->required()->options(fn () => Branch::where('is_active', true)->orderBy('code')->pluck('name', 'id'))
                    ->default(fn () => Branch::orderByDesc('is_hq')->orderBy('code')->value('id')),
                Select::make('asset_id')->label('Equipment (for breakdown work orders)')->placeholder('—')->searchable()
                    ->options(fn () => MaintenanceAsset::where('owner', 'own')->where('status', '<>', 'retired')->orderBy('number')->get()->mapWithKeys(fn ($a) => [$a->id => "{$a->number} {$a->name}"])),
            ]),
            Section::make('Range')->columns(4)->visible(fn (Get $get) => ! in_array($get('kind'), ['power', 'door'], true))->schema([
                TextInput::make('min_temp')->label('Lowest °C')->numeric()->default(2.0),
                TextInput::make('max_temp')->label('Highest °C')->numeric()->default(8.0),
                TextInput::make('min_humidity')->label('Lowest humidity %')->numeric()->minValue(0)->maxValue(100)->visible(fn (Get $get) => $get('kind') === 'room'),
                TextInput::make('max_humidity')->label('Highest humidity %')->numeric()->minValue(0)->maxValue(100)->visible(fn (Get $get) => $get('kind') === 'room'),
            ]),
            Section::make('Alarms')->columns(2)->schema([
                TextInput::make('delay_minutes')->label('Alarm after this many minutes out of range')->numeric()->minValue(0)->maxValue(240)->default(10)
                    ->helperText('So opening the door to take something out is not an alarm. 10 suits most fridges.'),
                TextInput::make('silent_minutes')->label('Alarm when no reading for (minutes)')->numeric()->minValue(5)->maxValue(1440)->default(30)
                    ->helperText('A silent sensor (battery, Wi-Fi, power) could hide a warm fridge.'),
            ]),
            Section::make('How it reaches ZionEX')->columns(3)->schema([
                Radio::make('connection')->hiddenLabel()->options(IotSensor::CONNECTIONS)->default('direct')->live()->inline()->columnSpanFull(),
                Select::make('connection_id')->label('Account')->visible(fn (Get $get) => $get('connection') !== 'direct')
                    ->options(fn (Get $get) => IotConnection::where('provider', $get('connection'))->pluck('name', 'id'))->required(fn (Get $get) => $get('connection') !== 'direct'),
                TextInput::make('external_id')->label('The sensor’s id in that cloud')->maxLength(120)->visible(fn (Get $get) => $get('connection') !== 'direct')
                    ->required(fn (Get $get) => $get('connection') !== 'direct'),
                Text::make('After saving, this page shows the address the sensor posts its readings to.')->visible(fn (Get $get, ?IotSensor $record) => $get('connection') === 'direct' && ! $record),
            ]),
        ]);
    }

    /** The state now, at the top of a sensor's page, with the last 24 hours drawn. */
    public static function now(IotSensor $s): array
    {
        $tz = Erp::user()?->tenant?->timezone ?: 'UTC';
        $box = fn (string $label, string $value, ?string $note = null) => Text::make(new HtmlString('<div class="text-xs text-gray-500">'.e($label).'</div><div class="text-lg font-semibold">'
            .e($value).'</div>'.($note ? '<div class="text-xs text-gray-500">'.e($note).'</div>' : '')));
        $value = match (true) {
            $s->kind === 'power' => $s->last_power === null ? '—' : ($s->last_power ? 'On' : 'OFF'),
            $s->kind === 'door' => $s->last_door_open === null ? '—' : ($s->last_door_open ? 'OPEN' : 'Closed'),
            default => $s->last_temp === null ? '—' : rtrim(rtrim((string) $s->last_temp, '0'), '.').' °C'.($s->last_humidity !== null ? ' · '.$s->last_humidity.' %' : ''),
        };

        return [
            $box('Reading', $value, 'Range '.$s->range()),
            $box('State', IotSensor::STATUSES[$s->status] ?? $s->status),
            $box('Last reading', $s->last_reading_at ? $s->last_reading_at->timezone($tz)->format('j M H:i') : 'None yet', $s->last_reading_at?->diffForHumans()),
            $box('Battery', $s->battery !== null ? $s->battery.' %' : '—'),
            Text::make(new HtmlString(view('filament.iot.chart', ['sensor' => $s, 'tz' => $tz])->render()))->columnSpanFull(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->weight('bold')->description(fn (IotSensor $s) => $s->branch?->name.' · '.(IotSensor::KINDS[$s->kind][0] ?? $s->kind)),
                TextColumn::make('reading')->label('Now')->state(fn (IotSensor $s) => match (true) {
                    $s->kind === 'power' => $s->last_power === null ? '—' : ($s->last_power ? 'On' : 'OFF'),
                    $s->kind === 'door' => $s->last_door_open === null ? '—' : ($s->last_door_open ? 'OPEN' : 'Closed'),
                    default => $s->last_temp === null ? '—' : rtrim(rtrim((string) $s->last_temp, '0'), '.').' °C'.($s->last_humidity !== null ? ' · '.$s->last_humidity.' %' : ''),
                })->weight('bold')->description(fn (IotSensor $s) => 'Range '.$s->range()),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => IotSensor::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'ok' => 'success', 'alarm' => 'danger', 'silent' => 'warning', default => 'gray'
                    }),
                TextColumn::make('last_reading_at')->label('Last reading')->since()->placeholder('None yet'),
                TextColumn::make('battery')->label('Battery')->suffix(' %')->placeholder('—')->color(fn ($state) => $state !== null && $state < 20 ? 'danger' : null),
                TextColumn::make('connection')->label('Via')->formatStateUsing(fn ($state) => IotSensor::CONNECTIONS[$state] ?? $state)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->poll('60s')
            ->filters([
                SelectFilter::make('status')->options(IotSensor::STATUSES),
                SelectFilter::make('kind')->label('Watches')->options(collect(IotSensor::KINDS)->map(fn ($k) => $k[0])),
                SelectFilter::make('branch_id')->label('Branch')->options(fn () => Branch::orderBy('code')->pluck('name', 'id')),
            ])
            ->emptyStateHeading('No sensors yet')
            ->emptyStateDescription('Add a sensor for each fridge, cold room, freezer or store room you want watched.');
    }

    /** "Record a check" and the sensor's address. */
    public static function sensorActions(): array
    {
        return [
            Action::make('check')->label('Record a check')->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->visible(fn (IotSensor $s) => $s->measuresTemperature() && self::allowed('iot.respond'))
                ->schema([TextInput::make('seen')->label('The display shows (°C)')->numeric(),
                    Toggle::make('ok')->label('All is well (door shut, nothing frozen or warm)')->default(true),
                    TextInput::make('note')->maxLength(255)])
                ->action(fn (IotSensor $record, array $data) => Erp::run(fn ($actor) => app(Sensors::class)->check($actor, $record,
                    ($data['seen'] ?? '') === '' || $data['seen'] === null ? null : (float) $data['seen'], (bool) $data['ok'], $data['note'] ?? null), 'Check signed')),
            Action::make('address')->label('Sensor address')->icon(Heroicon::OutlinedLink)->color('gray')
                ->visible(fn (IotSensor $s) => $s->connection === 'direct' && self::allowed('iot.manage'))
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (IotSensor $s) => new HtmlString(view('filament.iot.address', ['sensor' => $s])->render())),
            Action::make('newAddress')->label('New address')->icon(Heroicon::OutlinedArrowPath)->color('gray')->requiresConfirmation()
                ->visible(fn (IotSensor $s) => $s->connection === 'direct' && self::allowed('iot.manage'))
                ->modalDescription('The old address stops working at once: set the new one on the device. Use this when a device is replaced or the address was shared.')
                ->action(fn (IotSensor $record) => Erp::run(fn ($actor) => app(Sensors::class)->newToken($actor, $record), 'New address made')),
        ];
    }

    public static function getRelations(): array
    {
        return [AlarmsRelationManager::class, ChecksRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIotSensors::route('/'),
            'create' => CreateIotSensor::route('/create'),
            'edit' => EditIotSensor::route('/{record}/edit'),
        ];
    }
}
