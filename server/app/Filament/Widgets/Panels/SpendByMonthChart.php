<?php

namespace App\Filament\Widgets\Panels;

use App\Domain\Purchasing\SpendAnalytics;
use App\Filament\Pages\SpendAnalyticsPage;
use Filament\Widgets\ChartWidget;

/** Dashboard: spend with suppliers, month by month (as on Purchasing → Spend analytics). */
class SpendByMonthChart extends ChartWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Spend by month';

    protected ?string $description = 'Posted supplier bills, before VAT';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $months = SpendAnalytics::for(self::tenantId(), self::today()->startOfYear()->toDateString(), self::today()->toDateString())->byMonth();

        return ['datasets' => [['label' => 'Spend', 'data' => array_map(fn ($m) => round($m['spend'] / 100, 2), $months)]], 'labels' => array_column($months, 'month')];
    }

    protected function getOptions(): ?array
    {
        return ['plugins' => ['legend' => ['display' => false]]];
    }

    public static function canView(): bool
    {
        return SpendAnalyticsPage::canAccess();
    }
}
