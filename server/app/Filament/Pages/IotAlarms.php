<?php

namespace App\Filament\Pages;

use App\Domain\Iot\Sensors;
use App\Filament\Resources\IotSensors\IotSensorResource;
use App\Filament\Resources\IotSensors\RelationManagers\AlarmsRelationManager;
use App\Filament\Resources\MaintenanceOrders\MaintenanceOrderResource;
use App\Filament\Support\Erp;
use App\Models\IotAlarm;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Sensors → Alarms: what is out of range or silent, and saying what was done (the reminders stop). */
class IotAlarms extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Sensors';

    protected static ?string $navigationLabel = 'Alarms';

    protected static ?string $title = 'Sensor alarms';

    protected static ?string $slug = 'iot-alarms';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.table-page';

    public static function canAccess(): bool
    {
        return IotSensorResource::allowed('iot.view');
    }

    public static function getNavigationBadge(): ?string
    {
        if (! self::canAccess()) {
            return null;
        }
        $n = IotSensorResource::scoped(IotAlarm::query())->where('status', 'open')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function getSubheading(): ?string
    {
        return 'People on duty are told at once and every 15 minutes until someone acknowledges with what was done; managers after 30 minutes. An alarm closes when it is acknowledged and the reading is back in range.';
    }

    public function table(Table $table): Table
    {
        return self::columns($table->query(fn () => IotSensorResource::scoped(IotAlarm::query())->with('sensor'))->defaultSort('started_at', 'desc')->poll('60s'))
            ->filters([TernaryFilter::make('open')->label('Not closed')->default(true)
                ->queries(true: fn ($q) => $q->where('status', '<>', 'closed'), false: fn ($q) => $q->where('status', 'closed'), blank: fn ($q) => $q)])
            ->emptyStateHeading('No alarms')
            ->recordActions(self::actions());
    }

    /** The alarm columns, also on a sensor's page. */
    public static function columns(Table $table, bool $withSensor = true): Table
    {
        $tz = Erp::user()?->tenant?->timezone ?: 'UTC';

        return $table->columns(array_values(array_filter([
            TextColumn::make('number')->label('No.')->color('gray'),
            $withSensor ? TextColumn::make('sensor.name')->label('Sensor')->weight('bold')
                ->url(fn (IotAlarm $a) => IotSensorResource::getUrl('edit', ['record' => $a->sensor_id])) : null,
            TextColumn::make('kind')->label('What')->badge()->color('danger')->formatStateUsing(fn ($state) => IotAlarm::KINDS[$state] ?? $state),
            TextColumn::make('worst')->label('Worst')->placeholder('—')->formatStateUsing(fn ($state, IotAlarm $a) => $state === null ? null
                : rtrim(rtrim((string) $state, '0'), '.').($a->kind === 'humidity' ? ' %' : ' °C')),
            TextColumn::make('started_at')->label('From')->formatStateUsing(fn ($state) => $state?->timezone($tz)->format('j M H:i'))
                ->description(fn (IotAlarm $a) => ($a->ended_at ? 'for ' : 'going on, ').AlarmsRelationManager::duration($a)),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => IotAlarm::STATUSES[$state] ?? $state)
                ->color(fn ($state) => match ($state) {
                    'open' => 'danger', 'acknowledged' => 'warning', default => 'success'
                }),
            TextColumn::make('action')->label('What was done')->wrap()->placeholder('—')->formatStateUsing(fn ($state) => IotAlarm::ACTIONS[$state] ?? $state)
                ->description(fn (IotAlarm $a) => trim(($a->acknowledged_by_name ? $a->acknowledged_by_name.', '.$a->acknowledged_at?->timezone($tz)->format('j M H:i') : '').($a->notes ? ' — '.$a->notes : ''), ' —')),
            TextColumn::make('order.number')->label('Work order')->placeholder('—')
                ->url(fn (IotAlarm $a) => $a->maintenance_order_id && MaintenanceOrderResource::canViewAny() ? MaintenanceOrderResource::getUrl('view', ['record' => $a->maintenance_order_id]) : null),
        ])));
    }

    /** "Acknowledge": what was done. */
    public static function actions(): array
    {
        return [
            Action::make('acknowledge')->label('Acknowledge')->icon(Heroicon::OutlinedCheck)->color('success')
                ->visible(fn (IotAlarm $a) => $a->status !== 'closed' && IotSensorResource::allowed('iot.respond'))
                ->modalDescription('Check the stock (has it been warm, how long?) and say what you did. Moved or discarded stock: name the product, batch and quantity.')
                ->schema([Select::make('action')->label('What was done')->options(IotAlarm::ACTIONS)->required(), Textarea::make('notes')->rows(3)])
                ->action(fn (IotAlarm $record, array $data) => Erp::run(fn ($actor) => app(Sensors::class)->acknowledge($actor, $record, $data['action'], $data['notes'] ?? null), 'Acknowledged')),
        ];
    }
}
