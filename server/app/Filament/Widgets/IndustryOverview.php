<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Industry;
use App\Support\IndustryContext;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * The company view ("All industries"): each industry's sales today and this month, side by side. Choosing one at the
 * top opens its own dashboard (the same figures for its branches, plus its own highlights).
 */
class IndustryOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $heading = 'Industries';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $user = Erp::user();
        $today = CarbonImmutable::now($user->tenant?->timezone ?: 'UTC')->startOfDay();
        $allowed = Erp::branchIds('sales.view');
        $stats = [];
        foreach (Industry::inUse()->orderBy('sort')->withCount('branches')->get() as $industry) {
            $branches = DB::table('branches')->where('industry_id', $industry->id)->pluck('id')->all();
            if ($allowed !== null) {
                $branches = array_values(array_intersect($branches, $allowed));
            }
            $sum = fn (CarbonImmutable $from, CarbonImmutable $to) => (int) DB::table('sales')->where('tenant_id', $user->tenant_id)->where('status', 'posted')
                ->whereIn('branch_id', $branches)->where('occurred_at', '>=', $from->utc())->where('occurred_at', '<', $to->utc())->sum('total_minor');
            $stats[] = Stat::make($industry->name, FinanceUi::money($sum($today, $today->addDay())))
                ->description('today · '.FinanceUi::money($sum($today->startOfMonth(), $today->addDay())).' this month · '.$industry->branches_count.' '.str('branch')->plural($industry->branches_count));
        }

        return $stats;
    }

    public static function canView(): bool
    {
        return IndustryContext::selected() === null && Erp::canAnywhere('sales.view') && Industry::inUse()->exists();
    }
}
