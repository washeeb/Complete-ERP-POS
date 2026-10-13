<?php

namespace App\Filament\Pages;

use App\Domain\Support\SystemHealth;
use App\Filament\Support\Erp;
use App\Filament\Support\ExportsPage;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
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

    /** The slowest pages and till calls of the last 7 days, worst first. */
    public function slowPages()
    {
        return DB::table('slow_requests')->where('created_at', '>=', now()->subDays(7))
            ->groupBy('method', 'path')->selectRaw('method, path, COUNT(*) AS times, ROUND(AVG(ms)) AS avg_ms, MAX(ms) AS max_ms, ROUND(AVG(queries)) AS avg_queries, MAX(created_at) AS last_at')
            ->orderByRaw('COUNT(*) * AVG(ms) DESC')->limit(20)->get();
    }
}
