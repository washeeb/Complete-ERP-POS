<?php

namespace App\Filament\Widgets;

use App\Domain\Purchasing\ReorderLevels;
use App\Filament\Pages\ExpiryReturns;
use App\Filament\Pages\ReorderSuggestions;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Warehouse;
use App\Support\IndustryContext;
use App\Support\Settings;
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
            $days = (int) app(Settings::class)->get('stock.expiry_alert_days', $user->tenant_id);
            $link = ExpiryReturns::canAccess() ? ExpiryReturns::getUrl() : null;
            $stats[] = Stat::make('Expired, still in stock', (string) ($n = $expiring(-1)))->color($n ? 'danger' : 'success')
                ->description($n ? 'Batches to dispose of or return' : 'Nothing expired on the shelves')->url($link);
            $stats[] = Stat::make("Batches expiring in {$days} days", (string) ($n = $expiring($days)))->color($n ? 'warning' : 'success')
                ->description('Medicines to sell first, mark down or return')->url($link);
        } elseif ($industry?->module_key === 'grocery') {
            $stats[] = Stat::make('Batches expiring in 7 days', (string) ($n = $expiring(7)))->color($n ? 'warning' : 'success')
                ->description('Candidates for a markdown sticker');
        }
        if (Erp::canAnywhere('purchasing.manage') || Erp::canAnywhere('warehouse.manage')) {
            $low = 0;
            foreach (Warehouse::whereIn('id', $warehouses)->where('is_active', true)->get() as $w) {
                $low += count(app(ReorderLevels::class)->low($w));
            }
            $stats[] = Stat::make('Low stock', (string) $low)->color($low ? 'warning' : 'success')
                ->description($low ? 'Products at or below their minimum' : 'Everything above its minimum')
                ->url(ReorderSuggestions::canAccess() ? ReorderSuggestions::getUrl() : null);
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
