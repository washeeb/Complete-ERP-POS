<?php

namespace App\Filament\Pages;

use App\Domain\Iot\Clouds;
use App\Filament\Resources\IotSensors\IotSensorResource;
use App\Filament\Support\Erp;
use App\Models\IotConnection;
use App\Models\IotSensor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/** Sensors → Sensor clouds: SensorPush and Tuya accounts whose sensors ZionEX reads every few minutes. */
class IotClouds extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCloud;

    protected static string|UnitEnum|null $navigationGroup = 'Sensors';

    protected static ?string $navigationLabel = 'Sensor clouds';

    protected static ?string $title = 'Sensor clouds';

    protected static ?string $slug = 'iot-clouds';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return IotSensorResource::allowed('iot.manage');
    }

    public function getSubheading(): ?string
    {
        return 'For sensors that report to their maker’s cloud instead of to ZionEX: their readings are fetched every 5 minutes. Most sensors can send to ZionEX directly (see a sensor’s “Sensor address”).';
    }

    private static function form(): array
    {
        return [
            Radio::make('provider')->label('Cloud')->options(IotConnection::PROVIDERS)->default('sensorpush')->required()->live()->inline(),
            TextInput::make('name')->label('Name (for you)')->maxLength(255),
            TextInput::make('credentials.email')->label('SensorPush account e-mail')->email()->visible(fn (Get $get) => $get('provider') === 'sensorpush'),
            TextInput::make('credentials.password')->label('SensorPush password')->password()->revealable()->visible(fn (Get $get) => $get('provider') === 'sensorpush')
                ->helperText('Leave empty when changing to keep the one saved. The account needs the SensorPush gateway.'),
            TextInput::make('credentials.client_id')->label('Tuya Access ID')->visible(fn (Get $get) => $get('provider') === 'tuya'),
            TextInput::make('credentials.secret')->label('Tuya Access Secret')->password()->revealable()->visible(fn (Get $get) => $get('provider') === 'tuya')
                ->helperText('From a cloud project at iot.tuya.com, with the Smart Life app account linked. Leave empty when changing to keep the one saved.'),
            Select::make('region')->label('Tuya data centre')->options(IotConnection::TUYA_REGIONS)->default('eu')->visible(fn (Get $get) => $get('provider') === 'tuya'),
            Toggle::make('is_active')->label('In use')->default(true),
        ];
    }

    public function table(Table $table): Table
    {
        $clouds = app(Clouds::class);

        return $table
            ->query(fn () => IotConnection::query())
            ->columns([
                TextColumn::make('name')->weight('bold')->description(fn (IotConnection $c) => IotConnection::PROVIDERS[$c->provider] ?? $c->provider),
                TextColumn::make('sensors')->label('Sensors')->state(fn (IotConnection $c) => IotSensor::where('connection_id', $c->id)->count()),
                TextColumn::make('last_polled_at')->label('Last read')->since()->placeholder('Not yet'),
                TextColumn::make('last_error')->label('Problem')->wrap()->color('danger')->placeholder('—'),
                IconColumn::make('is_active')->boolean()->label('In use'),
            ])
            ->emptyStateHeading('No sensor clouds')
            ->headerActions([
                Action::make('add')->label('Add a sensor cloud')->icon(Heroicon::OutlinedPlus)->schema(self::form())
                    ->action(fn (array $data) => Erp::run(fn ($actor) => $clouds->save($actor, new IotConnection, $data), 'Saved')),
            ])
            ->recordActions([
                Action::make('change')->label('Change')->icon(Heroicon::OutlinedPencilSquare)->color('gray')->schema(self::form())
                    ->fillForm(fn (IotConnection $c) => ['provider' => $c->provider, 'name' => $c->name, 'region' => $c->region, 'is_active' => $c->is_active,
                        'credentials' => array_intersect_key($c->credentials ?? [], array_flip(['email', 'client_id']))])
                    ->action(fn (IotConnection $record, array $data) => Erp::run(fn ($actor) => $clouds->save($actor, $record, $data), 'Saved')),
                Action::make('read')->label('Read now')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                    ->action(function (IotConnection $record) use ($clouds) {
                        $n = $clouds->poll($record);
                        $record->refresh();
                        $record->last_error
                            ? Notification::make()->danger()->title('Could not read the readings')->body($record->last_error)->send()
                            : Notification::make()->success()->title("{$n} reading(s) read")->send();
                    }),
            ]);
    }
}
