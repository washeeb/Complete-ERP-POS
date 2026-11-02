<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** Dashboard: cash — tills open now, shifts closed today, over and short this month (closed shifts' counts). */
class CashPanel extends StatsOverviewWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Cash';

    protected function getStats(): array
    {
        $branches = self::branches('cash.view');
        $shifts = fn () => DB::table('shifts')->where('tenant_id', self::tenantId())->when($branches !== null, fn ($q) => $q->whereIn('branch_id', $branches));
        $today = self::today();
        $overShort = (int) $shifts()->where('status', 'closed')->where('closed_at', '>=', $today->startOfMonth()->utc())->sum('over_short_minor');
        $short = (int) $shifts()->where('status', 'closed')->where('closed_at', '>=', $today->startOfMonth()->utc())->where('over_short_minor', '<', 0)->count();

        return [
            Stat::make('Tills open now', (string) $shifts()->where('status', 'open')->count()),
            Stat::make('Shifts closed today', (string) $shifts()->where('status', 'closed')->where('closed_at', '>=', $today->utc())->count()),
            Stat::make('Over / short this month', FinanceUi::money($overShort))->color($overShort < 0 ? 'danger' : ($overShort > 0 ? 'warning' : 'success'))
                ->description($short ? "{$short} shift(s) short" : 'No shift short'),
        ];
    }

    public static function canView(): bool
    {
        return Erp::canAnywhere('cash.view');
    }
}
