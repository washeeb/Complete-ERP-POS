<?php

namespace App\Filament\Vendor\Pages;

use App\Filament\Vendor\Widgets\LicenceAttention;
use App\Filament\Vendor\Widgets\LicenceFigures;
use Filament\Pages\Dashboard as BaseDashboard;

/** The licence business at a glance: licences by state, revenue, money owed, and what needs attention. */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [LicenceFigures::class, LicenceAttention::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
