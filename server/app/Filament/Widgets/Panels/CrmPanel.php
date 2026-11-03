<?php

namespace App\Filament\Widgets\Panels;

use App\Filament\Pages\CrmFollowUps;
use App\Filament\Pages\CrmPipeline;
use App\Filament\Resources\CrmLeads\CrmLeadResource;
use App\Filament\Support\CrmUi;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/** Dashboard: CRM — the open pipeline (and weighted), won this month, new leads, the person's follow-ups due. */
class CrmPanel extends StatsOverviewWidget
{
    use PanelScope;

    public int|string|array $columnSpan = 'full'; // public: the dashboard sets each panel's width

    protected ?string $heading = 'Customers and deals';

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $t = self::tenantId();
        $b = self::branches('crm.view');
        $deals = fn () => DB::table('crm_deals')->where('crm_deals.tenant_id', $t)->when($b !== null, fn ($q) => $q->whereIn('crm_deals.branch_id', $b));
        $open = $deals()->join('crm_stages', 'crm_stages.id', '=', 'crm_deals.stage_id')->where('crm_deals.status', 'open')
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(value_minor), 0) AS v, COALESCE(SUM(value_minor * probability / 100), 0) AS w')->first();
        $since = self::today()->startOfMonth()->utc();
        $won = $deals()->where('status', 'won')->where('closed_at', '>=', $since)->selectRaw('COUNT(*) AS n, COALESCE(SUM(value_minor), 0) AS v')->first();
        $leads = DB::table('crm_leads')->where('tenant_id', $t)->when($b !== null, fn ($q) => $q->whereIn('branch_id', $b))->where('created_at', '>=', $since)->count();
        $due = DB::table('crm_activities')->where('tenant_id', $t)->whereNull('done_at')->where('assigned_to', Erp::user()?->id)
            ->where('due_at', '<=', self::today()->endOfDay()->utc())->count();

        return [
            Stat::make('Open deals', FinanceUi::money((int) $open->v))->description("{$open->n} deal(s) · weighted ".FinanceUi::money((int) $open->w))->url(CrmPipeline::getUrl()),
            Stat::make('Won this month', FinanceUi::money((int) $won->v))->description("{$won->n} deal(s)")->color('success'),
            Stat::make('New leads this month', (string) $leads)->url(CrmLeadResource::getUrl('index')),
            Stat::make('Your follow-ups today', (string) $due)->color($due ? 'warning' : 'success')->description($due ? 'Including overdue' : 'Nothing waiting')
                ->url(CrmFollowUps::getUrl()),
        ];
    }

    public static function canView(): bool
    {
        return CrmUi::canView();
    }
}
