<?php

namespace App\Filament\Resources\Devices;

use App\Domain\Devices\DeviceService;
use App\Filament\Resources\Devices\Pages\CreateDevice;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Support\Erp;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Till;
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
use UnitEnum;

class DeviceResource extends Resource
{
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
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
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
