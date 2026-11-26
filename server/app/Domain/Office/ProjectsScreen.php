<?php

namespace App\Domain\Office;

use App\Domain\Projects\Projects;
use App\Domain\Projects\Timesheets;
use App\Filament\Pages\MyTime;
use App\Filament\Pages\ProjectRates;
use App\Filament\Pages\TimeApprovals;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\Fields;
use App\Filament\Support\ProjectUi;
use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectMilestone;
use App\Models\ProjectRate;
use App\Models\ProjectTask;
use App\Models\TimeEntry;
use App\Models\User;
use App\Security\Authorizer;
use App\Support\IndustryContext;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Projects in the new back office (part 27 of the rewrite), as the current pages: Projects (list and one project with
 * its figures, tasks, milestones and costs), My time, Time to approve and Hourly rates. Same queries (the resource's and
 * pages', scoped to the person's branches) and rules: the Projects and Timesheets services (ProjectsController).
 */
final class ProjectsScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public const TONE = ['active' => 'informative', 'done' => 'success', 'on_hold' => 'warning', 'planned' => 'subtle', 'cancelled' => 'subtle'];

    public const TIME_TONE = ['approved' => 'success', 'submitted' => 'informative', 'rejected' => 'danger', 'draft' => 'subtle'];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private function m(): array
    {
        $c = $this->user->tenant?->currency_code ?? 'GHS';

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    private function amount(?int $minor): string
    {
        $e = $this->m()['exponent'];

        return $minor === null ? '' : rtrim(rtrim(number_format($minor / (10 ** $e), $e, '.', ''), '0'), '.');
    }

    private static function num(mixed $v): string
    {
        $v = (string) ($v ?? '');

        return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v;
    }

    public static function canView(): bool
    {
        return ProjectResource::canViewAny();
    }

    public static function canManage(): bool
    {
        return ProjectUi::can('projects.manage');
    }

    public static function canBill(): bool
    {
        return ProjectUi::can('projects.bill');
    }

    public static function canTime(): bool
    {
        return MyTime::canAccess();
    }

    public static function canApprove(): bool
    {
        return TimeApprovals::canAccess();
    }

    public static function canRates(): bool
    {
        return ProjectRates::canAccess();
    }

    // -------------------------------------------------------------------------------------------- Projects

    public function query(): Builder
    {
        return ProjectResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q, status (active by default; all), billing, page, per */
    public function projects(array $p): array
    {
        $q = $this->query();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('projects.number', 'ilike', "%{$s}%")->orWhere('projects.name', 'ilike', "%{$s}%"));
        }
        $status = (string) ($p['status'] ?? 'active');
        if (isset(Project::STATUSES[$status])) {
            $q->where('projects.status', $status);
        } else {
            $status = 'all';
        }
        if (isset(Project::BILLING[$p['billing'] ?? ''])) {
            $q->where('projects.billing', $p['billing']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $rows = $q->withSum('costs', 'cost_minor')->orderByDesc('projects.created_at')->forPage($page, $per)->get();

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'status' => $status, 'statuses' => self::choices(Project::STATUSES),
            'billings' => self::choices(Project::BILLING), 'can_create' => ProjectResource::canCreate(),
            'rows' => $rows->map(fn (Project $r) => ['id' => $r->id, 'number' => $r->number, 'name' => $r->name, 'customer' => $r->customer?->name,
                'billing' => match ($r->billing) {
                    'milestones' => 'Milestones', 'time' => 'Time & materials', default => 'Internal'
                }, 'status' => $r->status, 'status_label' => Project::STATUSES[$r->status] ?? $r->status, 'tone' => self::TONE[$r->status] ?? 'subtle',
                'cost' => (int) ($r->costs_sum_cost_minor ?? 0), 'budget' => $r->budget_cost_minor, 'due' => $r->ends_on?->toDateString(),
                'late' => $r->isOpen() && (bool) $r->ends_on?->isPast(), 'manager' => $r->manager?->name])->all()];
    }

    /** People who may be the project manager (see projects somewhere). */
    private function managers(): array
    {
        $authz = app(Authorizer::class);

        return IndustryContext::userScope(User::where('is_active', true))->orderBy('name')->get()
            ->filter(fn ($u) => $authz->allowsAnywhere($u, 'projects.view'))->map(fn ($u) => ['id' => $u->id, 'label' => $u->name])->values()->all();
    }

    /** People who may do a task: log time on the project's branch. */
    private function workers(Project $p): array
    {
        $authz = app(Authorizer::class);

        return IndustryContext::userScope(User::where('is_active', true))->orderBy('name')->get()
            ->filter(fn ($u) => $authz->allows($u, 'projects.time', $p->branch))->map(fn ($u) => ['id' => $u->id, 'label' => $u->name])->values()->all();
    }

    public function project(?Project $r, ?string $customer = null): array
    {
        $branches = ProjectUi::branches();
        $manage = self::canManage();
        $open = $r ? $r->isOpen() : true;
        $base = $this->m() + ['id' => $r?->id, 'number' => $r?->number, 'open' => $open, 'status' => $r?->status, 'status_label' => $r ? Project::STATUSES[$r->status] ?? $r->status : null,
            'tone' => $r ? self::TONE[$r->status] ?? 'subtle' : null, 'billings' => self::choices(Project::BILLING),
            'statuses' => self::choices(array_slice(Project::STATUSES, 0, 3, true)), 'branches' => self::choices($branches), 'managers' => $this->managers(),
            'values' => ['name' => (string) $r?->name, 'status' => $r && in_array($r->status, ['planned', 'active', 'on_hold'], true) ? $r->status : 'active',
                'billing' => $r?->billing ?? 'time', 'customer_id' => (string) ($r?->customer_id ?? $customer ?? ''),
                'customer_name' => $r?->customer?->name ?? ($customer ? Customer::find($customer)?->name : null),
                'branch_id' => (string) ($r?->branch_id ?? array_key_first($branches) ?? ''), 'manager_id' => (string) ($r ? $r->manager_id : $this->user->id),
                'starts_on' => (string) $r?->starts_on?->toDateString(), 'ends_on' => (string) $r?->ends_on?->toDateString(),
                'budget_cost' => $this->amount($r?->budget_cost_minor), 'contract' => $this->amount($r?->contract_minor), 'hourly_rate' => $this->amount($r?->hourly_rate_minor),
                'markup' => $r ? self::num($r->materials_markup_bp / 100) : '0', 'service_product_id' => (string) $r?->service_product_id,
                'service_name' => $r?->serviceProduct?->name, 'description' => (string) $r?->description],
            'actions' => ['edit' => $open && ($r ? $manage : ProjectResource::canCreate())]];
        if (! $r) {
            return $base;
        }
        $f = app(Projects::class)->figures($r);
        $bill = self::canBill();
        $hasMaterial = $r->costs()->where('kind', 'material')->exists();
        $logged = TimeEntry::where('project_id', $r->id)->selectRaw('task_id, sum(minutes) as m')->groupBy('task_id')->pluck('m', 'task_id');

        return array_replace($base, ['billed' => $r->isBilled(), 'figures' => $f, 'contract_minor' => $r->contract_minor,
            'actions' => $base['actions'] + ['issue' => $open && $manage, 'return' => $open && $manage && $hasMaterial, 'invoice_time' => $r->billing === 'time' && $bill,
                'finish' => $open && $manage, 'cancel' => $open && $manage, 'reopen' => ! $open && $manage, 'tasks' => $open && $manage,
                'milestones' => $open && $manage && $r->billing === 'milestones', 'invoice_milestone' => $bill],
            'warehouses' => self::choices(Fields::warehouses('stock.adjust')), 'workers' => $this->workers($r), 'task_statuses' => self::choices(ProjectTask::STATUSES),
            'cost_kinds' => self::choices(ProjectCost::KINDS),
            'tasks' => $r->tasks()->with('assignee')->orderBy('sort')->orderBy('created_at')->get()->map(fn (ProjectTask $t) => ['id' => $t->id, 'name' => $t->name,
                'who' => $t->assignee?->name, 'status' => $t->status, 'status_label' => ProjectTask::STATUSES[$t->status] ?? $t->status,
                'tone' => match ($t->status) {
                    'done' => 'success', 'doing' => 'informative', default => 'subtle'
                }, 'due' => $t->due_on?->toDateString(), 'late' => $t->status !== 'done' && (bool) $t->due_on?->isPast(),
                'hours' => TimeEntry::hours((int) ($logged[$t->id] ?? 0)).($t->estimate_hours ? ' of '.self::num($t->estimate_hours) : ''),
                'values' => ['name' => $t->name, 'assigned_to' => (string) $t->assigned_to, 'status' => $t->status, 'due_on' => (string) $t->due_on?->toDateString(),
                    'estimate_hours' => self::num($t->estimate_hours), 'billable' => (bool) $t->billable, 'sort' => (string) $t->sort]])->all(),
            'milestones' => $r->billing !== 'milestones' ? [] : ProjectMilestone::where('project_id', $r->id)->with('invoice')->orderBy('sort')->orderBy('created_at')->get()
                ->map(fn (ProjectMilestone $m) => ['id' => $m->id, 'name' => $m->name, 'amount' => (int) $m->amount_minor, 'due' => $m->due_on?->toDateString(),
                    'status' => $m->status, 'status_label' => ProjectMilestone::STATUSES[$m->status] ?? $m->status,
                    'tone' => match ($m->status) {
                        'invoiced' => 'success', 'ready' => 'warning', default => 'subtle'
                    }, 'invoice' => $m->invoice_id ? ['number' => $m->invoice?->number, 'open' => "/office/invoices/{$m->invoice_id}"] : null,
                    'can_edit' => $m->status !== 'invoiced' && $open && $manage, 'can_invoice' => $m->status !== 'invoiced' && $bill,
                    'values' => ['name' => $m->name, 'amount' => $this->amount($m->amount_minor), 'due_on' => (string) $m->due_on?->toDateString(),
                        'status' => $m->status === 'ready' ? 'ready' : 'pending', 'sort' => (string) $m->sort]])->all(),
            'costs' => ProjectCost::where('project_id', $r->id)->with('invoice')->orderByDesc('occurred_on')->orderByDesc('created_at')->limit(500)->get()
                ->map(fn (ProjectCost $c) => ['id' => $c->id, 'date' => $c->occurred_on?->toDateString(), 'kind' => $c->kind, 'kind_label' => ProjectCost::KINDS[$c->kind] ?? $c->kind,
                    'description' => $c->description, 'qty' => self::num($c->qty), 'cost' => (int) $c->cost_minor, 'bill' => $c->billable ? (int) $c->bill_minor : null,
                    'invoice' => $c->invoice_id ? ['number' => $c->invoice?->number, 'open' => "/office/invoices/{$c->invoice_id}"] : null,
                    'unbilled' => $c->billable && ! $c->invoice_id])->all()]);
    }

    // -------------------------------------------------------------------------------------------- My time

    /** Open projects the person may log time on, with their open tasks. */
    public function timeProjects(): array
    {
        return collect(MyTime::projects())->map(fn ($l, $id) => ['id' => (string) $id, 'label' => $l])->values()->all();
    }

    public function timeTasks(string $projectId): array
    {
        return ProjectTask::where('project_id', $projectId)->where('status', '<>', 'done')->orderBy('sort')->get(['id', 'name'])
            ->map(fn ($t) => ['id' => $t->id, 'label' => $t->name])->all();
    }

    public function myTime(?string $week): array
    {
        [$from, $to] = Timesheets::week($week ?: now()->toDateString());
        $entries = TimeEntry::with(['project', 'task'])->where('user_id', $this->user->id)->whereBetween('work_date', [$from, $to])->orderBy('work_date')->orderBy('created_at')->get();
        $running = TimeEntry::with(['project', 'task'])->where('user_id', $this->user->id)->whereNotNull('timer_started_at')->first();

        return ['week' => $from, 'from' => $from, 'to' => $to, 'today' => now()->toDateString(), 'total' => TimeEntry::hours((int) $entries->sum('minutes')),
            'previous' => CarbonImmutable::parse($from)->subWeek()->toDateString(), 'next' => ($n = CarbonImmutable::parse($from)->addWeek())->isBefore(now()) ? $n->toDateString() : null,
            'can_submit' => $entries->whereIn('status', ['draft', 'rejected'])->isNotEmpty(), 'projects' => $this->timeProjects(),
            'running' => $running ? ['project' => $running->project?->name, 'task' => $running->task?->name, 'note' => $running->note,
                'since' => $running->timer_started_at->toIso8601String(), 'for' => TimeEntry::hours((int) $running->timer_started_at->diffInMinutes(now()))] : null,
            'rows' => $entries->map(fn (TimeEntry $e) => ['id' => $e->id, 'day' => $e->work_date?->toDateString(), 'project' => $e->project?->name, 'task' => $e->task?->name,
                'note' => $e->note, 'hours' => $e->timer_started_at ? 'Running…' : TimeEntry::hours((int) $e->minutes), 'status' => $e->status,
                'status_label' => TimeEntry::STATUSES[$e->status] ?? $e->status, 'tone' => self::TIME_TONE[$e->status] ?? 'subtle', 'reason' => $e->reject_reason,
                'can_change' => in_array($e->status, ['draft', 'rejected'], true) && ! $e->timer_started_at,
                'values' => ['project_id' => $e->project_id, 'task_id' => (string) $e->task_id, 'work_date' => $e->work_date?->toDateString(),
                    'hours' => self::num(round($e->minutes / 60, 2)), 'note' => (string) $e->note]])->all()];
    }

    // -------------------------------------------------------------------------------------------- Time to approve

    public function approvals(): array
    {
        $rows = TimeApprovals::waiting()->with(['user', 'project', 'task'])->orderBy('work_date')->get();

        return ['people' => $rows->groupBy('user_id')->map(fn ($list) => ['id' => $list->first()->user_id, 'name' => $list->first()->user?->name ?? '—',
            'total' => TimeEntry::hours((int) $list->sum('minutes')),
            'rows' => $list->map(fn (TimeEntry $e) => ['id' => $e->id, 'day' => $e->work_date?->toDateString(), 'project' => $e->project?->name, 'task' => $e->task?->name,
                'note' => $e->note, 'hours' => TimeEntry::hours((int) $e->minutes), 'billable' => (bool) $e->billable])->values()->all()])->values()->all()];
    }

    // -------------------------------------------------------------------------------------------- Hourly rates

    public function rates(): array
    {
        $authz = app(Authorizer::class);
        $people = User::where('is_active', true)->orderBy('name')->get()->filter(fn (User $u) => $authz->allowsAnywhere($u, 'projects.time'));
        $rates = ProjectRate::whereIn('user_id', $people->pluck('id'))->get()->keyBy('user_id');

        return $this->m() + ['rows' => $people->map(function (User $u) use ($rates) {
            $r = $rates->get($u->id);

            return ['id' => $u->id, 'name' => $u->name, 'cost' => $r?->cost_rate_minor, 'bill' => $r?->bill_rate_minor,
                'values' => ['cost' => $this->amount($r?->cost_rate_minor ?? 0), 'bill' => $this->amount($r?->bill_rate_minor)]];
        })->values()->all()];
    }
}
