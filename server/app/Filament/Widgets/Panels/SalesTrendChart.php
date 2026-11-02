<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Support\Erp;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;

/** Dashboard: sales day by day (posted sales, the company's days), for the branches the person may see. */
class SalesTrendChart extends ChartWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Sales trend';

    protected ?string $maxHeight = '260px';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = in_array($this->filter, ['7', '30', '90'], true) ? (int) $this->filter : 30;
        $to = self::today()->addDay();
        $from = $to->subDays($days);
        $branches = self::branches('sales.view');
        $rows = DB::table('sales')->where('tenant_id', self::tenantId())->where('status', 'posted')
            ->when($branches !== null, fn ($q) => $q->whereIn('branch_id', $branches))
            ->where('occurred_at', '>=', $from->utc())->where('occurred_at', '<', $to->utc())
            ->selectRaw("to_char((occurred_at AT TIME ZONE 'UTC') AT TIME ZONE ?, 'YYYY-MM-DD') AS d, SUM(total_minor) AS s", [self::tz()])
            ->groupBy('d')->pluck('s', 'd');
        $labels = $values = [];
        for ($d = $from; $d < $to; $d = $d->addDay()) {
            $labels[] = $d->format($days > 31 ? 'j M' : 'D j');
            $values[] = round(((int) ($rows[$d->toDateString()] ?? 0)) / 100, 2);
        }

        return ['datasets' => [['label' => 'Sales', 'data' => $values, 'fill' => true, 'tension' => .3]], 'labels' => $labels];
    }

    public static function canView(): bool
    {
        return Erp::canAnywhere('sales.view');
    }
}
