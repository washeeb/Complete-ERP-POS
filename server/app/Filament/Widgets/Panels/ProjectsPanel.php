<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Pages\MyTime;
use App\Filament\Pages\TimeApprovals;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Filament\Support\ProjectUi;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** Dashboard: projects — those in progress, over budget, time waiting for approval, unbilled time and materials, my week. */
class ProjectsPanel extends StatsOverviewWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Projects';

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $t = self::tenantId();
        $b = self::branches('projects.view');
        $open = DB::table('projects')->where('tenant_id', $t)->whereIn('status', ['planned', 'active', 'on_hold'])->when($b !== null, fn ($q) => $q->whereIn('branch_id', $b));
        $over = (clone $open)->whereNotNull('budget_cost_minor')
            ->whereRaw('(SELECT COALESCE(SUM(cost_minor), 0) FROM project_costs c WHERE c.project_id = projects.id) > projects.budget_cost_minor')->count();
        $unbilled = (int) DB::table('project_costs as c')->join('projects as p', 'p.id', '=', 'c.project_id')->where('p.tenant_id', $t)->where('p.billing', 'time')
            ->when($b !== null, fn ($q) => $q->whereIn('p.branch_id', $b))->where('c.billable', true)->whereNull('c.invoice_id')->sum('c.bill_minor');
        $mine = (int) DB::table('time_entries')->where('user_id', Erp::user()?->id)->where('work_date', '>=', self::today()->startOfWeek()->toDateString())->sum('minutes');
        $stats = [
            Stat::make('Projects in progress', (string) (clone $open)->count())->description($over ? "{$over} over budget" : 'None over budget')
                ->color($over ? 'danger' : 'success')->url(ProjectResource::getUrl('index')),
            Stat::make('Not yet invoiced', FinanceUi::money($unbilled))->description('Time and materials'),
            Stat::make('My hours this week', intdiv($mine, 60).':'.str_pad((string) ($mine % 60), 2, '0', STR_PAD_LEFT))->url(MyTime::canAccess() ? MyTime::getUrl() : null),
        ];
        if (TimeApprovals::canAccess()) {
            $n = TimeApprovals::waiting()->distinct('user_id')->count('user_id');
            $stats[] = Stat::make('Timesheets to approve', (string) $n)->color($n ? 'warning' : 'success')->url(TimeApprovals::getUrl());
        }

        return $stats;
    }

    public static function canView(): bool
    {
        return ProjectUi::can('projects.view');
    }
}
