<?php

namespace App\Filament\Vendor\Resources\SyncDevices;

use App\Domain\Licensing\Vendor\SyncDevices;
use App\Filament\Vendor\Resources\SyncDevices\Pages\ListSyncDevices;
use App\Filament\Vendor\VendorUi;
use App\Models\Vendor\VendorSyncDevice;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Copies of the SES Licensing desktop app allowed to sync with this server. */
class SyncDeviceResource extends Resource
{
    protected static ?string $model = VendorSyncDevice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'Set-up';

    protected static ?string $modelLabel = 'sync device';

    protected static ?int $navigationSort = 9;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => VendorSyncDevice::STATUSES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success', 'revoked' => 'danger', default => 'warning'
                    }),
                TextColumn::make('last_seen_at')->label('Last seen')->dateTime()->placeholder('—'),
                TextColumn::make('last_ip')->label('From')->placeholder('—'),
                TextColumn::make('created_by')->label('Created by'),
            ])
            ->recordActions([
                Action::make('revoke')->label('Revoke')->color('danger')->requiresConfirmation()
                    ->modalDescription('This copy of the app can no longer sync. What it has on its PC stays there.')
                    ->visible(fn (VendorSyncDevice $r) => $r->status !== 'revoked')
                    ->action(fn (VendorSyncDevice $r) => VendorUi::run(fn ($a) => app(SyncDevices::class)->revoke($a, $r), 'Device revoked')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListSyncDevices::route('/')];
    }
}
