<?php

namespace App\Filament\Pages;

use App\Domain\Support\SystemHealth;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** The launch checklist's live checks: scheduler, backups, disk, settings, licence and anything stuck. */
class SystemHealthPage extends Page
{
    use ExportsPage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Health';

    protected static ?string $title = 'System health';

    protected static ?string $slug = 'health';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.system-health';

    public static function canAccess(): bool
    {
        return Erp::can('admin.settings.manage');
    }

    public function checks(): array
    {
        return app(SystemHealth::class)->run();
    }
}
