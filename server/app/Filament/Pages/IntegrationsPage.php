<?php

namespace App\Filament\Pages;

use App\Domain\Integrations\IntegrationsOverview;
use App\Domain\Licensing\LicenseManager;
use App\Filament\Support\Erp;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Setup → Integrations (paid add-on "integrations"): every connection to the outside world on one page — set up or
 * not, when it last worked, what failed this week — with the way to its set-up and test. Hidden unless the licence
 * names the add-on.
 */
class IntegrationsPage extends Page
{
    public const ADDON = 'integrations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Integrations';

    protected static ?string $title = 'Integrations';

    protected static ?string $slug = 'integrations';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.integrations';

    public static function canAccess(): bool
    {
        return app(LicenseManager::class)->hasAddon(self::ADDON) && (Erp::can('admin.settings.manage') || Erp::can('messaging.manage'));
    }

    public function getSubheading(): ?string
    {
        return 'Every connection to the outside world: whether it is set up, when it last worked, and what failed this week.';
    }

    /** @return list<array<string, mixed>> */
    public function cards(): array
    {
        return app(IntegrationsOverview::class)->cards((string) Erp::user()->tenant_id);
    }
}
