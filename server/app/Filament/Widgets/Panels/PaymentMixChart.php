<?php

namespace App\Filament\Widgets\Panels;

use App\Domain\Sales\ReceivablesService;
use App\Filament\Support\Erp;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/** Dashboard: how customers paid this month (posted sales' payments by method). */
class PaymentMixChart extends ChartWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'How customers paid';

    protected ?string $description = 'This month';

    protected ?string $maxHeight = '240px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $branches = self::branches('sales.view');
        $rows = DB::table('sale_payments as p')->join('sales as s', 's.id', '=', 'p.sale_id')
            ->where('s.tenant_id', self::tenantId())->where('s.status', 'posted')
            ->when($branches !== null, fn ($q) => $q->whereIn('s.branch_id', $branches))
            ->where('s.occurred_at', '>=', self::today()->startOfMonth()->utc())
            ->groupBy('p.method')->selectRaw('p.method, SUM(p.amount_minor) AS a')->orderByDesc('a')->pluck('a', 'method');
        $palette = ['#2563eb', '#16a34a', '#f59e0b', '#9333ea', '#dc2626', '#0891b2', '#64748b'];

        return [
            'datasets' => [['data' => $rows->map(fn ($a) => round($a / 100, 2))->values()->all(), 'backgroundColor' => array_slice($palette, 0, max(1, $rows->count()))]],
            'labels' => $rows->keys()->map(fn ($m) => ReceivablesService::METHODS[$m] ?? ucfirst(str_replace('_', ' ', (string) $m)))->all(),
        ];
    }

    protected function getOptions(): ?array
    {
        return ['scales' => ['x' => ['display' => false], 'y' => ['display' => false]], 'plugins' => ['legend' => ['position' => 'bottom']]];
    }

    public static function canView(): bool
    {
        return Erp::canAnywhere('sales.view');
    }
}
