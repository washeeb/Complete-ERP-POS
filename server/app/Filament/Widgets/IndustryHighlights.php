<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Support\IndustryContext;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** The chosen industry's own dashboard figures (Pharmacy: prescriptions and expiry; Grocery: near-expiry stock). */
class IndustryHighlights extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getHeading(): ?string
    {
        return IndustryContext::industry()?->name;
    }

    protected function getStats(): array
    {
        $industry = IndustryContext::industry();
        $user = Erp::user();
        $branches = Erp::branchIds('stock.view') ?? [];
        $warehouses = DB::table('warehouses')->whereIn('branch_id', $branches)->pluck('id')->all();
        $today = CarbonImmutable::now($user->tenant?->timezone ?: 'UTC')->startOfDay();
        $expiring = fn (int $days) => (int) DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
            ->whereIn('l.warehouse_id', $warehouses)->where('l.on_hand', '>', 0)->whereNotNull('b.expires_on')
            ->where('b.expires_on', '<=', $today->addDays($days)->toDateString())->distinct()->count('l.batch_id');
        $stats = [Stat::make('Branches', (string) count($branches))];

        if ($industry?->module_key === 'pharmacy') {
            $stats[] = Stat::make('Prescriptions today', (string) DB::table('prescriptions')->whereIn('branch_id', $branches)
                ->where('created_at', '>=', $today->utc())->count());
            $stats[] = Stat::make('Batches expiring in 90 days', (string) ($n = $expiring(90)))->color($n ? 'warning' : 'success')
                ->description('Medicines to sell first, return or write off');
        } elseif ($industry?->module_key === 'grocery') {
            $stats[] = Stat::make('Batches expiring in 7 days', (string) ($n = $expiring(7)))->color($n ? 'warning' : 'success')
                ->description('Candidates for a markdown sticker');
        }
        $stats[] = Stat::make('Stock value', FinanceUi::money((int) DB::table('stock_levels')->whereIn('warehouse_id', $warehouses)
            ->selectRaw('COALESCE(SUM(ROUND(on_hand * COALESCE(avg_cost_minor, 0))), 0) AS v')->value('v')));

        return $stats;
    }

    public static function canView(): bool
    {
        return IndustryContext::selected() !== null && Erp::canAnywhere('stock.view');
    }
}
