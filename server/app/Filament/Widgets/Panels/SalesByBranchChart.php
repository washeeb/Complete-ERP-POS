<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Support\Erp;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/** Dashboard: this month's sales, branch by branch (the branches the person may see). */
class SalesByBranchChart extends ChartWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Sales by branch';

    protected ?string $description = 'This month';

    protected ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $branches = self::branches('sales.view');
        $rows = DB::table('sales as s')->join('branches as b', 'b.id', '=', 's.branch_id')
            ->where('s.tenant_id', self::tenantId())->where('s.status', 'posted')
            ->when($branches !== null, fn ($q) => $q->whereIn('s.branch_id', $branches))
            ->where('s.occurred_at', '>=', self::today()->startOfMonth()->utc())
            ->groupBy('b.name')->selectRaw('b.name, SUM(s.total_minor) AS t')->orderByDesc('t')->pluck('t', 'name');

        return ['datasets' => [['label' => 'Sales', 'data' => $rows->map(fn ($t) => round($t / 100, 2))->values()->all()]], 'labels' => $rows->keys()->all()];
    }

    protected function getOptions(): ?array
    {
        return ['plugins' => ['legend' => ['display' => false]]];
    }

    public static function canView(): bool
    {
        return Erp::canAnywhere('sales.view');
    }
}
