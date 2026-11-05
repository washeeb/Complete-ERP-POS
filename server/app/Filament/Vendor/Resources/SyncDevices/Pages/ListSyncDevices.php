<?php

namespace App\Filament\Vendor\Resources\SyncDevices\Pages;

use App\Domain\Licensing\Vendor\SyncDevices;
use App\Filament\Vendor\Resources\SyncDevices\SyncDeviceResource;
use App\Filament\Vendor\VendorUi;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListSyncDevices extends ListRecords
{
    protected static string $resource = SyncDeviceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')->label('New device')->icon('heroicon-o-plus')
                ->schema([TextInput::make('name')->label('Which PC?')->placeholder('Owner\'s PC')->required()->maxLength(80)])
                ->action(function (array $data) {
                    $r = VendorUi::run(fn ($a) => app(SyncDevices::class)->create($a, $data['name']));
                    Notification::make()->success()->title('One-time code')->persistent()
                        ->body('Type this in the app under Sync → Connect within '.SyncDevices::CODE_MINUTES." minutes. It is not shown again: {$r['code']}")->send();
                }),
        ];
    }
}
