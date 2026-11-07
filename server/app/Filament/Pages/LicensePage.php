<?php

namespace App\Filament\Pages;

use App\Domain\Licensing\LicenseException;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Licensing\LicenseService;
use App\Filament\Support\Erp;
use App\Models\License;
use App\Models\User;
use App\Modules\ModuleRegistry;
use BackedEnum;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

/** Licence status, the installation code to send to the supplier, and where the key is entered. */
class LicensePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Licence';

    protected static ?string $title = 'Licence';

    protected static ?string $slug = 'licence';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.license';

    /** Included with the system when the licence does not narrow them; everything else is named on the licence. */
    private const STANDARD = ['finance', 'hr'];

    private const FEATURES = ['finance' => 'Finance', 'hr' => 'HR and payroll', 'white_label' => 'Own branding', 'remote_access' => 'Remote access', 'integrations' => 'Integrations',
        'crm' => 'CRM', 'projects' => 'Projects and job costing', 'service' => 'Service desk and maintenance', 'iot' => 'Sensors', 'offsite_backup' => 'Off-site backups'];

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

    /** The same code as a QR picture, to photograph or scan into the licence app. */
    public function installationQr(): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(220, 2), new SvgImageBackEnd)))->writeString($this->installationCode());
    }

    public function usage(): array
    {
        return ['branches' => DB::table('branches')->count(), 'devices' => DB::table('devices')->where('status', '<>', 'revoked')->count(),
            'users' => User::licensedCount()];
    }

    /** What the licence covers, in words: the licence itself holds short codes. A planned module says it is not ready. */
    public function covers(): array
    {
        $s = $this->status();
        $named = fn (array $keys) => array_map(fn ($k) => self::FEATURES[$k] ?? Str::headline($k), $keys);
        $features = $s['features'] ?? [];
        $registry = app(ModuleRegistry::class);

        return [
            'Features' => $s['features'] === null ? 'All standard features' : (implode(', ', $named(array_values(array_intersect($features, self::STANDARD)))) ?: 'None'),
            'Paid add-ons' => implode(', ', $named(array_values(array_diff($features, self::STANDARD)))) ?: 'None',
            'Industry modules' => $s['modules'] === null ? 'All' : (implode(', ', array_map(function ($key) use ($registry) {
                if (! $registry->has($key)) {
                    return Str::headline($key);
                }
                $module = $registry->get($key);

                return $module->name.($module->isAvailable() ? '' : ' (not available yet)');
            }, $s['modules'])) ?: 'None'),
        ];
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
