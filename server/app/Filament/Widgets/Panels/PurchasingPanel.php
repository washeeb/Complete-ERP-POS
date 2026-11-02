<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** Dashboard: buying — orders waiting for approval, open orders, deliveries due this week, ordered this month. */
class PurchasingPanel extends StatsOverviewWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Buying';

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $branches = self::branches('purchasing.view');
        $orders = fn () => DB::table('purchase_orders')->where('tenant_id', self::tenantId())->when($branches !== null, fn ($q) => $q->whereIn('branch_id', $branches));
        $today = self::today();
        $url = PurchaseOrderResource::getUrl('index');
        $waiting = $orders()->where('status', 'pending_approval')->count();
        $open = $orders()->whereIn('status', ['approved', 'partially_received']);
        $due = (clone $open)->whereNotNull('expected_on')->where('expected_on', '<=', $today->addDays(7)->toDateString())->count();

        return [
            Stat::make('Waiting for approval', (string) $waiting)->color($waiting ? 'warning' : 'success')->url($url),
            Stat::make('Open orders', (string) (clone $open)->count())->description(FinanceUi::money((int) (clone $open)->sum('total_minor')).' ordered')->url($url),
            Stat::make('Due this week', (string) $due)->color($due ? 'info' : 'gray')->description('Deliveries expected in the next 7 days, or late')->url($url),
            Stat::make('Ordered this month', FinanceUi::money((int) $orders()->whereNotIn('status', ['draft', 'pending_approval', 'cancelled'])
                ->where('approved_at', '>=', $today->startOfMonth()->utc())->sum('total_minor'))),
        ];
    }

    public static function canView(): bool
    {
        return Erp::canAnywhere('purchasing.view');
    }
}
