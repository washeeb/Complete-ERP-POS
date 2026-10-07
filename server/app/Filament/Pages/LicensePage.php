<?php

namespace App\Filament\Pages;

use App\Domain\Licensing\LicenseException;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Licensing\LicenseService;
use App\Filament\Support\Erp;
use App\Models\License;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** Licence status, the installation code to send to Synclytics, and where the key is entered. */
class LicensePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Licence';

    protected static ?string $title = 'Licence';

    protected static ?string $slug = 'licence';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.license';

    public static function canAccess(): bool
    {
        return Erp::user() !== null; // everyone sees why the system is locked; only the owner/GM can change it
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Erp::canAnywhere('setup.company.manage');
    }

    public function status(): array
    {
        return app(LicenseManager::class)->status();
    }

    public function canManage(): bool
    {
        return Erp::can('setup.company.manage') && config('erp.node.role') !== 'branch';
    }

    public function installationCode(): string
    {
        return LicenseService::installationCode();
    }

    public function usage(): array
    {
        return ['branches' => DB::table('branches')->count(), 'devices' => DB::table('devices')->where('status', '<>', 'revoked')->count(),
            'users' => DB::table('users')->where('is_active', true)->count()];
    }

    public function lastCheckin(): ?License
    {
        return License::where('is_current', true)->first();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('activate')->label('Enter licence key')->icon(Heroicon::OutlinedKey)->visible(fn () => $this->canManage())
                ->schema([Textarea::make('key')->label('Licence key (starts with SYN1-)')->required()->rows(5)])
                ->action(function (array $data) {
                    try {
                        app(LicenseService::class)->activate(Erp::actor(), $data['key']);
                    } catch (LicenseException $e) {
                        Notification::make()->danger()->title('Not activated')->body($e->getMessage())->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title('Licence activated')->send();
                }),
            Action::make('checkin')->label('Check in now')->icon(Heroicon::OutlinedArrowPath)->color('gray')
                ->visible(fn () => $this->canManage() && $this->status()['checkin_url'])
                ->action(function () {
                    $r = app(LicenseService::class)->checkIn();
                    Notification::make()->{$r['ok'] ? 'success' : 'warning'}()->title($r['message'])->send();
                }),
        ];
    }
}
