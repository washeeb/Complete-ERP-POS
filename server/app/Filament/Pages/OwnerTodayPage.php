<?php

namespace App\Filament\Pages;

use App\Domain\Messaging\DailySummary;
use App\Domain\Reports\OwnerToday;
use App\Filament\Resources\ReviewItems\ReviewItemResource;
use App\Filament\Support\Erp;
use App\Models\Tenant;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * Today (owner request 2026-10-24): the owner's phone view — the business today on one screen, large and simple, that
 * refreshes itself every minute. "Add to Home screen" on the phone opens it like an app. Yesterday is one tap away.
 */
class OwnerTodayPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static ?string $navigationLabel = 'Today';

    protected static ?string $title = 'Today';

    protected static ?string $slug = 'today';

    protected static ?int $navigationSort = -10;

    protected string $view = 'filament.pages.owner-today';

    #[Url]
    public bool $yesterday = false;

    public static function canAccess(): bool
    {
        return Erp::canAnywhere(DailySummary::PERMISSION);
    }

    public function data(): array
    {
        $tenant = Tenant::findOrFail(Erp::user()->tenant_id);
        $day = $this->yesterday ? Carbon::now($tenant->timezone ?: 'UTC')->subDay() : null;

        return app(OwnerToday::class)->build($tenant, Erp::branchIds(DailySummary::PERMISSION), $day);
    }

    /** Where each kind of attention item is dealt with (only the pages this person may open). */
    public function link(?string $kind): ?string
    {
        return match ($kind) {
            'health' => SystemHealthPage::canAccess() ? SystemHealthPage::getUrl() : null,
            'review' => ReviewItemResource::canViewAny() ? ReviewItemResource::getUrl() : null,
            'expiry' => ExpiryReturns::canAccess() ? ExpiryReturns::getUrl() : (BatchesExpiry::canAccess() ? BatchesExpiry::getUrl() : null),
            default => null,
        };
    }
}
