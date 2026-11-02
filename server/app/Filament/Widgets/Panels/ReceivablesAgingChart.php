<?php

namespace App\Filament\Widgets\Panels;

use App\Domain\Customers\CustomerLedger;
use App\Filament\Support\Erp;
use Filament\Widgets\ChartWidget;

/** Dashboard: what customers owe, by how late (as on Finance → Aged receivables; company-wide only, as that page). */
class ReceivablesAgingChart extends ChartWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Customers owe, by how late';

    protected ?string $maxHeight = '260px';

    public const BUCKETS = ['current' => 'Not yet due', 'd1_30' => '1–30 days', 'd31_60' => '31–60', 'd61_90' => '61–90', 'd90_plus' => 'Over 90'];

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $aging = app(CustomerLedger::class)->aging();
        $totals = array_map(fn ($k) => round(array_sum(array_column($aging, $k)) / 100, 2), array_keys(self::BUCKETS));

        return ['datasets' => [['label' => 'Owed', 'data' => $totals, 'backgroundColor' => ['#16a34a', '#f59e0b', '#f97316', '#ef4444', '#b91c1c']]], 'labels' => array_values(self::BUCKETS)];
    }

    protected function getOptions(): ?array
    {
        return ['plugins' => ['legend' => ['display' => false]]];
    }

    public static function canView(): bool
    {
        return Erp::canAnywhere('receivables.view') && Erp::branchIds('receivables.view') === null;
    }
}
