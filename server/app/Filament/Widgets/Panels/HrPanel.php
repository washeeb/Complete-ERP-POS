<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Support\Erp;
use App\Models\PayrollRun;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** Dashboard: people — staff, who is on leave today, leave waiting for a decision, the last payroll. */
class HrPanel extends StatsOverviewWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'People';

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $t = self::tenantId();
        $today = self::today()->toDateString();
        $run = DB::table('payroll_runs')->where('tenant_id', $t)->orderByDesc('year')->orderByDesc('month')->first(['number', 'status', 'year', 'month']);
        $waiting = DB::table('leave_requests')->where('tenant_id', $t)->where('status', 'requested')->count();

        return [
            Stat::make('Staff', (string) DB::table('employees')->where('tenant_id', $t)->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'terminated'))->count()),
            Stat::make('On leave today', (string) DB::table('leave_requests')->where('tenant_id', $t)->where('status', 'approved')
                ->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->count()),
            Stat::make('Leave waiting', (string) $waiting)->color($waiting ? 'warning' : 'success')
                ->url(LeaveRequestResource::canViewAny() ? LeaveRequestResource::getUrl('index') : null),
            Stat::make('Last payroll', $run ? date('M Y', mktime(0, 0, 0, (int) $run->month, 1, (int) $run->year)) : '—')
                ->description($run ? (PayrollRun::STATUSES[$run->status] ?? $run->status) : 'None yet')
                ->url(PayrollRunResource::canViewAny() ? PayrollRunResource::getUrl('index') : null),
        ];
    }

    public static function canView(): bool
    {
        return Erp::canAnywhere('hr.view');
    }
}
