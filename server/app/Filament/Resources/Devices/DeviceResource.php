<?php

namespace App\Filament\Resources\Devices;

use App\Domain\Devices\DeviceService;
use App\Domain\Remote\DeviceHealth;
use App\Filament\Resources\Devices\Pages\CreateDevice;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Support\Erp;
use App\Filament\Support\GlobalSearch;
use App\Models\Branch;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\Till;
use App\Support\ClientInfo;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use UnitEnum;

class DeviceResource extends Resource
{
    use GlobalSearch;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return static::searchDetails(['Status' => $record->status]);
    }

    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return Erp::canAnywhere('admin.devices.manage');
    }

    public static function canCreate(): bool
    {
        return Erp::canAnywhere('admin.devices.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return false; // devices change through register / re-issue / revoke actions only
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $ids = Erp::branchIds('admin.devices.manage');

        return parent::getEloquentQuery()->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids));
    }

    public static function form(Schema $schema): Schema
    {
        $ids = Erp::branchIds('admin.devices.manage');

        return $schema->components([
            Select::make('branch_id')->label('Branch')->required()->live()
                ->options(fn () => Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get()
                    ->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"])),
            Select::make('till_id')->label('Till (for POS devices)')
                ->options(fn (Get $get) => Till::where('branch_id', $get('branch_id'))->orderBy('code')->pluck('name', 'id')),
            TextInput::make('name')->required()->maxLength(80)->helperText('e.g. "Front counter PC" or "Handheld 2"'),
            Select::make('kind')->required()->options(fn (Get $get) => Device::kindsFor($get('branch_id')))->default('pos'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('kind')->formatStateUsing(fn ($state) => Device::KINDS[$state] ?? $state),
                TextColumn::make('branch.code')->label('Branch'),
                TextColumn::make('till.code')->label('Till')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'active' => 'success', 'pending' => 'warning', default => 'danger',
                }),
                TextColumn::make('enrollment_expires_at')->label('Code expires')->since()->placeholder('—'),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('never'),
                // The till's own report every few minutes: what is wrong now, in plain words (hover for the details).
                TextColumn::make('health')->label('Health')->wrap()
                    ->state(fn (Device $record) => $record->health_at === null ? null : (implode('; ', app(DeviceHealth::class)->problems($record)) ?: 'OK'))
                    ->color(fn ($state) => $state === 'OK' ? 'success' : ($state ? 'danger' : null))->placeholder('—')
                    ->tooltip(fn (Device $record) => $record->health ? collect($record->health)->map(fn ($v, $k) => "{$k}: ".(is_bool($v) ? ($v ? 'yes' : 'no') : ($v ?? '—')))->implode(' · ')
                        .' · reported '.$record->health_at?->diffForHumans() : null),
                TextColumn::make('last_client')->label('Last used from')->placeholder('—')->wrap()
                    ->state(fn (Device $r) => ClientInfo::summary($r->last_client, $r->last_ip))
                    ->description(fn (Device $r) => $r->last_client['mac'] ?? null),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                // A fix the till runs at its next health check (Remote access add-on; control permission).
                Action::make('fix')->label('Send a fix')->icon(Heroicon::OutlinedWrenchScrewdriver)->color('gray')
                    ->visible(fn (Device $record) => $record->isActive() && in_array($record->kind, ['pos', 'handheld'], true) && app(DeviceHealth::class)->canFix(Erp::user(), $record))
                    ->modalDescription('The till does it within about 5 minutes (at its next health check) and reports back below. Unsent sales are never touched.')
                    ->schema([
                        Select::make('command')->label('What to do')->required()->options(DeviceCommand::COMMANDS),
                        TextInput::make('reason')->label('Why (optional)')->maxLength(255),
                    ])
                    ->action(fn (Device $record, array $data) => Erp::run(fn ($actor) => app(DeviceHealth::class)->queue($actor, $record, $data['command'], $data['reason'] ?? null), 'Fix sent')),
                Action::make('fixes')->label('Fixes')->icon(Heroicon::OutlinedClock)->color('gray')
                    ->visible(fn (Device $record) => DeviceCommand::where('device_id', $record->id)->exists())
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (Device $record) => new HtmlString('<table style="width:100%;font-size:.85rem"><tr><th align="left">When</th><th align="left">What</th><th align="left">By</th><th align="left">Result</th></tr>'
                        .DeviceCommand::where('device_id', $record->id)->latest()->limit(20)->get()->map(fn ($c) => '<tr><td>'.e($c->created_at->format('j M H:i')).'</td><td>'
                            .e(DeviceCommand::COMMANDS[$c->command] ?? $c->command).'</td><td>'.e($c->requested_by_name).'</td><td>'.e(ucfirst($c->status)
                            .($c->result ? ': '.mb_strimwidth($c->result, 0, 200, '…') : '')).'</td></tr>')->implode('').'</table>')),
                Action::make('reissue')->label('New code')->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (Device $r) => $r->status === 'pending')
                    ->requiresConfirmation()
                    ->action(function (Device $record) {
                        $code = Erp::run(fn ($actor) => app(DeviceService::class)->reissueCode($actor, $record));
                        self::showCode($record, $code);
                    }),
                Action::make('revoke')->label('Revoke')->color('danger')->icon(Heroicon::OutlinedNoSymbol)
                    ->visible(fn (Device $r) => $r->status !== 'revoked')
                    ->requiresConfirmation()
                    ->modalDescription('The device and everyone signed in on it are signed out immediately. A revoked device must be registered again.')
                    ->schema([TextInput::make('reason')->required()->maxLength(255)->placeholder('Lost, stolen, replaced…')])
                    ->action(fn (Device $record, array $data) => Erp::run(fn ($actor) => app(DeviceService::class)
                        ->revoke($actor, $record, $data['reason']), 'Device revoked')),
            ]);
    }

    public static function showCode(Device $device, string $code): void
    {
        Notification::make()->success()->persistent()
            ->title("Enrolment code for {$device->name}: {$code}")
            ->body('Shown once. Enter it on the device within '.$device->enrollment_expires_at->diffForHumans(syntax: true).'. If it is lost, issue a new code.')
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDevices::route('/'),
            'create' => CreateDevice::route('/create'),
        ];
    }
}
