<?php

namespace App\Filament\Widgets;

use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** The dashboard's headline figures; each appears only to people allowed to see it (and for their branches). */
class BusinessStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $user = Erp::user();
        $tz = $user?->tenant?->timezone ?: 'UTC';
        $today = CarbonImmutable::now($tz)->startOfDay();
        $stats = [];
        if (Erp::canAnywhere('sales.view')) {
            $ids = Erp::branchIds('sales.view');
            $sum = fn (CarbonImmutable $from, CarbonImmutable $to) => (int) DB::table('sales')->where('tenant_id', $user->tenant_id)->where('status', 'posted')
                ->when($ids !== null, fn ($q) => $q->whereIn('branch_id', $ids))->where('occurred_at', '>=', $from->utc())->where('occurred_at', '<', $to->utc())->sum('total_minor');
            $t = $sum($today, $today->addDay());
            $y = $sum($today->subDay(), $today);
            $stats[] = Stat::make('Sales today', FinanceUi::money($t))->description($y ? sprintf('%+d %% on yesterday at close', round(($t - $y) * 100 / max(1, $y))) : 'No sales yesterday')
                ->color($t >= $y ? 'success' : 'warning')
                ->chart(collect(range(6, 0))->map(fn ($d) => $sum($today->subDays($d), $today->subDays($d - 1)) / 100)->all());
            $stats[] = Stat::make('Sales this month', FinanceUi::money($sum($today->startOfMonth(), $today->addDay())));
        }
        if (Erp::canAnywhere('receivables.view') && Erp::branchIds('receivables.view') === null) {
            $stats[] = Stat::make('Customers owe', FinanceUi::money((int) DB::table('customer_balances')->where('tenant_id', $user->tenant_id)->where('balance_minor', '>', 0)->sum('balance_minor')));
        }
        if (Erp::canAnywhere('payables.view') && Erp::branchIds('payables.view') === null) {
            $stats[] = Stat::make('We owe suppliers', FinanceUi::money((int) DB::table('supplier_ledger')->where('tenant_id', $user->tenant_id)->sum('amount_minor')));
        }
        if (Erp::canAnywhere('insights.view')) {
            $n = DB::table('ai_alerts')->where('tenant_id', $user->tenant_id)->where('status', 'open')->whereIn('severity', ['high', 'warning'])
                ->when(! Erp::canAnywhere('insights.loss_prevention'), fn ($q) => $q->where('kind', '<>', 'loss'))
                ->when(($b = Erp::branchIds('insights.view')) !== null, fn ($q) => $q->whereIn('branch_id', $b))->count();
            $stats[] = Stat::make('Alerts to look at', (string) $n)->color($n ? 'danger' : 'success')->url(url('/admin/alerts'));
        }

        return $stats;
    }

    public static function canView(): bool
    {
        return Erp::canAnywhere('sales.view') || Erp::canAnywhere('insights.view') || Erp::canAnywhere('receivables.view');
    }
}
