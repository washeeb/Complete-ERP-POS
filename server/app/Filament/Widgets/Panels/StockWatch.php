<?php

namespace App\Filament\Widgets\Panels;

use App\Domain\Purchasing\ReorderLevels;
use App\Filament\Pages\ExpiryReturns;
use App\Filament\Pages\ReorderSuggestions;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Warehouse;
use App\Support\Settings;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** Dashboard: stock to act on — below minimum, expired and expiring batches — and what the stock is worth. */
class StockWatch extends StatsOverviewWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Stock';

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $warehouses = self::warehouses('stock.view');
        $today = self::today();
        $expiring = fn (int $days) => (int) DB::table('stock_batch_levels as l')->join('stock_batches as b', 'b.id', '=', 'l.batch_id')
            ->whereIn('l.warehouse_id', $warehouses)->where('l.on_hand', '>', 0)->whereNotNull('b.expires_on')
            ->where('b.expires_on', '<=', $today->addDays($days)->toDateString())->distinct()->count('l.batch_id');
        $days = (int) app(Settings::class)->get('stock.expiry_alert_days', self::tenantId());
        $expiry = ExpiryReturns::canAccess() ? ExpiryReturns::getUrl() : null;

        $low = 0;
        foreach (Warehouse::whereIn('id', $warehouses)->where('is_active', true)->get() as $w) {
            $low += count(app(ReorderLevels::class)->low($w));
        }

        return [
            Stat::make('Below minimum', (string) $low)->color($low ? 'warning' : 'success')
                ->description($low ? 'Products to order' : 'Everything above its minimum')->url(ReorderSuggestions::canAccess() ? ReorderSuggestions::getUrl() : null),
            Stat::make('Expired, still in stock', (string) ($n = $expiring(-1)))->color($n ? 'danger' : 'success')
                ->description($n ? 'Batches to dispose of or return' : 'Nothing expired on the shelves')->url($expiry),
            Stat::make("Expiring in {$days} days", (string) ($n = $expiring($days)))->color($n ? 'warning' : 'success')
                ->description('Sell first, mark down or return')->url($expiry),
            Stat::make('Stock value', FinanceUi::money((int) DB::table('stock_levels')->whereIn('warehouse_id', $warehouses)
                ->selectRaw('COALESCE(SUM(ROUND(on_hand * COALESCE(avg_cost_minor, 0))), 0) AS v')->value('v')))->description('At average cost'),
        ];
    }

    public static function canView(): bool
    {
        return Erp::canAnywhere('stock.view');
    }
}
