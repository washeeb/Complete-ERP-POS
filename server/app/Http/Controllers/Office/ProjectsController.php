<?php

namespace App\Http\Controllers\Office;

use App\Domain\Office\ProjectsScreen;
use App\Domain\Office\StockScreen;
use App\Domain\Projects\Projects;
use App\Domain\Projects\Timesheets;
use App\Domain\Support\Actor;
use App\Filament\Pages\MyTime;
use App\Filament\Pages\TimeApprovals;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Warehouse;
use App\Security\Authorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Projects in the new back office (App\Domain\Office\ProjectsScreen): projects (save, issue and return stock, invoice
 * time and materials, finish, cancel, reopen), their tasks and milestones (save, invoice), My time (add, change,
 * remove, timer, send the week), Time to approve (approve, send back) and Hourly rates — each through the Projects and
 * Timesheets services as the current pages.
 */
class ProjectsController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): ProjectsScreen
    {
        $this->allowed($can);

        return new ProjectsScreen($r->user());
    }

    private static function minor(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : FinanceUi::toMinor($v);
    }

    private function project(ProjectsScreen $screen, string $id): Project
    {
        return $screen->query()->findOrFail($id);
    }

    // -------------------------------------------------------------------------------------------- Projects

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ProjectsScreen::canView())->projects($request->query()));
    }

    public function show(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, $id ? ProjectsScreen::canView() : ProjectResource::canCreate());
        $customer = $request->query('customer');

        return response()->json($screen->project($id ? $this->project($screen, $id) : null, is_string($customer) && Customer::whereKey($customer)->exists() ? $customer : null));
    }

    public function save(Request $request, Projects $projects, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, $id ? ProjectsScreen::canManage() : ProjectResource::canCreate());
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'status' => ['nullable', 'in:planned,active,on_hold'], 'billing' => ['required', Rule::in(array_keys(Project::BILLING))],
            'customer_id' => ['nullable', 'uuid'], 'branch_id' => ['required', 'uuid'], 'manager_id' => ['nullable', 'uuid'], 'starts_on' => ['nullable', 'date'], 'ends_on' => ['nullable', 'date'],
            'budget_cost' => ['nullable', 'numeric', 'min:0'], 'contract' => ['nullable', 'numeric', 'min:0'], 'hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'markup' => ['nullable', 'numeric', 'min:0', 'max:500'], 'service_product_id' => ['nullable', 'uuid'], 'description' => ['nullable', 'string', 'max:5000']],
            ['name.*' => 'Name the project.', 'branch_id.*' => 'Choose the branch.', 'billing.*' => 'Choose how the project is billed.']);
        $p = $id ? $this->project($screen, $id) : new Project;
        if (! $id) {
            unset($d['status']); // a new project starts in progress, as the current form
        }
        foreach (['customer_id', 'manager_id', 'starts_on', 'ends_on', 'service_product_id', 'description'] as $k) {
            $d[$k] = ($d[$k] ?? '') === '' ? null : $d[$k];
        }
        $d['markup'] = $d['markup'] ?? 0;

        return $this->act(fn (Actor $actor) => ['id' => $projects->save($actor, $p, ProjectResource::toDomain($d))->id, 'message' => 'Saved.']);
    }

    /** issue · return (stock) · invoice_time (all, or the chosen costs) · finish · cancel · reopen. */
    public function action(Request $request, string $id, Projects $projects): JsonResponse
    {
        $act = (string) $request->input('action');
        $screen = $this->screen($request, $act === 'invoice_time' ? ProjectsScreen::canBill() : ProjectsScreen::canManage());
        $p = $this->project($screen, $id);
        if (in_array($act, ['issue', 'return'], true)) {
            $d = $request->validate(['warehouse_id' => ['required', 'uuid'], 'lines' => ['required', 'array', 'min:1'], 'lines.*.product_uom_id' => ['required', 'uuid'],
                'lines.*.qty' => ['required', 'numeric', 'min:0.001'], 'note' => ['nullable', 'string', 'max:255']],
                ['warehouse_id.*' => 'Choose the store.', 'lines.*' => 'Add at least one item.', 'lines.*.product_uom_id.*' => 'Choose the item and its unit.', 'lines.*.qty.*' => 'Enter how many.']);
            $lines = array_map(fn ($l) => ['product_uom_id' => $l['product_uom_id'], 'qty' => (string) $l['qty']], $d['lines']);

            return $this->act(function (Actor $actor) use ($projects, $p, $d, $lines, $act) {
                $projects->issue($actor, $p, Warehouse::findOrFail($d['warehouse_id']), $lines, ($d['note'] ?? '') ?: null, $act === 'return');

                return ['message' => $act === 'return' ? 'Returned to stock.' : 'Issued to the project.'];
            });
        }
        if ($act === 'invoice_time') {
            $ids = $request->validate(['cost_ids' => ['nullable', 'array'], 'cost_ids.*' => ['uuid']])['cost_ids'] ?? null;
            if ($ids !== null) {
                $ids = ProjectCost::where('project_id', $p->id)->whereIn('id', $ids)->pluck('id')->all();
            }

            return $this->act(fn (Actor $actor) => ['message' => 'Invoiced.', 'open' => '/office/invoices/'.$projects->invoiceTime($actor, $p, $ids ?: null)->id]);
        }

        return $this->act(fn (Actor $actor) => ['message' => match ($act) {
            'finish' => $projects->close($actor, $p, 'done') ? 'Project finished.' : '',
            'cancel' => $projects->close($actor, $p, 'cancelled') ? 'Project cancelled.' : '',
            'reopen' => $projects->reopen($actor, $p) ? 'Project reopened.' : '',
            default => abort(422, 'Unknown action.'),
        }]);
    }

    public function taskSave(Request $request, string $id, Projects $projects, ?string $task = null): JsonResponse
    {
        $p = $this->project($this->screen($request, ProjectsScreen::canManage()), $id);
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'assigned_to' => ['nullable', 'uuid'], 'status' => ['required', Rule::in(array_keys(ProjectTask::STATUSES))],
            'due_on' => ['nullable', 'date'], 'estimate_hours' => ['nullable', 'numeric', 'min:0'], 'billable' => ['boolean'], 'sort' => ['nullable', 'integer']], ['name.*' => 'Name the task.']);
        $t = $task ? ProjectTask::where('project_id', $p->id)->findOrFail($task) : new ProjectTask;
        $d['assigned_to'] = ($d['assigned_to'] ?? '') ?: null;
        $d['due_on'] = ($d['due_on'] ?? '') ?: null;

        return $this->act(fn (Actor $actor) => ['id' => $projects->saveTask($actor, $p, $t, $d)->id, 'message' => 'Saved.']);
    }

    public function milestoneSave(Request $request, string $id, Projects $projects, ?string $milestone = null): JsonResponse
    {
        $p = $this->project($this->screen($request, ProjectsScreen::canManage()), $id);
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'amount' => ['required', 'numeric', 'min:0.01'], 'due_on' => ['nullable', 'date'],
            'status' => ['required', 'in:pending,ready'], 'sort' => ['nullable', 'integer']], ['name.*' => 'Name the milestone.', 'amount.*' => 'Enter its amount (before tax).']);
        $m = $milestone ? ProjectMilestone::where('project_id', $p->id)->findOrFail($milestone) : new ProjectMilestone;
        $d['amount_minor'] = FinanceUi::toMinor($d['amount']);
        $d['due_on'] = ($d['due_on'] ?? '') ?: null;
        unset($d['amount']);

        return $this->act(fn (Actor $actor) => ['id' => $projects->saveMilestone($actor, $p, $m, $d)->id, 'message' => 'Saved.']);
    }

    public function milestoneInvoice(Request $request, string $id, string $milestone, Projects $projects): JsonResponse
    {
        $p = $this->project($this->screen($request, ProjectsScreen::canBill()), $id);
        $m = ProjectMilestone::where('project_id', $p->id)->findOrFail($milestone);

        return $this->act(fn (Actor $actor) => ['message' => 'Invoiced.', 'open' => '/office/invoices/'.$projects->invoiceMilestone($actor, $m)->id]);
    }

    /** Customers for "Customer", as the current form's search. */
    public function customers(Request $request): JsonResponse
    {
        $this->screen($request, ProjectsScreen::canView());
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return response()->json(['results' => Customer::where('is_active', true)->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('phone', 'ilike', "%{$q}%"))
            ->orderBy('name')->limit(30)->get()->map(fn ($c) => ['id' => $c->id, 'label' => $c->name, 'detail' => $c->phone])->all()]);
    }

    /** Services (not stock items) for "Service on invoices". */
    public function services(Request $request): JsonResponse
    {
        $this->screen($request, ProjectsScreen::canView());
        $q = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        return response()->json(['results' => Product::where('is_active', true)->where('track_stock', false)->where('name', 'ilike', "%{$q}%")
            ->orderBy('name')->limit(30)->get()->map(fn ($p) => ['id' => $p->id, 'label' => $p->name, 'detail' => $p->sku])->all()]);
    }

    /** Products and units for Issue / Return stock. */
    public function units(Request $request): JsonResponse
    {
        $this->screen($request, ProjectsScreen::canManage());

        return response()->json(['results' => (new StockScreen($request->user()))->units(mb_substr(trim((string) $request->query('q', '')), 0, 60), false)]);
    }

    // -------------------------------------------------------------------------------------------- My time

    public function myTime(Request $request): JsonResponse
    {
        $week = $request->query('week');

        return response()->json($this->screen($request, ProjectsScreen::canTime())->myTime(is_string($week) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) ? $week : null));
    }

    public function timeTasks(Request $request): JsonResponse
    {
        $screen = $this->screen($request, ProjectsScreen::canTime());
        $project = (string) $request->query('project', '');
        abort_unless(array_key_exists($project, MyTime::projects()), 404);

        return response()->json(['tasks' => $screen->timeTasks($project)]);
    }

    public function timeLog(Request $request, Timesheets $sheets, ?string $id = null): JsonResponse
    {
        $this->screen($request, ProjectsScreen::canTime());
        $d = $request->validate(['project_id' => ['required', 'uuid'], 'task_id' => ['nullable', 'uuid'], 'work_date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.05', 'max:24'], 'note' => ['nullable', 'string', 'max:255']],
            ['project_id.*' => 'Choose the project.', 'work_date.*' => 'Choose the day.', 'hours.*' => 'Enter the hours worked (more than 0, at most 24).']);
        $e = $id ? TimeEntry::where('user_id', $request->user()->id)->findOrFail($id) : new TimeEntry;
        $d['task_id'] = ($d['task_id'] ?? '') ?: null;

        return $this->act(fn (Actor $actor) => ['id' => $sheets->log($actor, $e, $d)->id, 'message' => $id ? 'Changed.' : 'Time added.']);
    }

    public function timeRemove(Request $request, string $id, Timesheets $sheets): JsonResponse
    {
        $this->screen($request, ProjectsScreen::canTime());
        $e = TimeEntry::where('user_id', $request->user()->id)->findOrFail($id);

        return $this->act(function (Actor $actor) use ($sheets, $e) {
            $sheets->delete($actor, $e);

            return ['message' => 'Removed.'];
        });
    }

    /** start (project, task, note) · stop · submit (the week). */
    public function timer(Request $request, Timesheets $sheets): JsonResponse
    {
        $this->screen($request, ProjectsScreen::canTime());
        $act = (string) $request->input('action');
        if ($act === 'start') {
            $d = $request->validate(['project_id' => ['required', 'uuid'], 'task_id' => ['nullable', 'uuid'], 'note' => ['nullable', 'string', 'max:255']], ['project_id.*' => 'Choose the project.']);
            abort_unless(array_key_exists($d['project_id'], MyTime::projects()), 422, 'Choose the project.');
            $project = Project::findOrFail($d['project_id']);
            $task = ($d['task_id'] ?? null) ? ProjectTask::where('project_id', $project->id)->findOrFail($d['task_id']) : null;

            return $this->act(fn (Actor $actor) => ['message' => $sheets->start($actor, $project, $task, ($d['note'] ?? '') ?: null) ? 'Timer started.' : '']);
        }
        if ($act === 'submit') {
            $week = (string) $request->validate(['week' => ['required', 'date']])['week'];

            return $this->act(fn (Actor $actor) => ['message' => $sheets->submitWeek($actor, $week).' entr(y/ies) sent for approval.']);
        }
        abort_unless($act === 'stop', 422, 'Unknown action.');

        return $this->act(function (Actor $actor) use ($sheets) {
            $sheets->stop($actor);

            return ['message' => 'Timer stopped.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Time to approve

    public function approvals(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ProjectsScreen::canApprove())->approvals());
    }

    /** approve · reject (reason): the chosen entries, among those waiting for this person. */
    public function approve(Request $request, Timesheets $sheets): JsonResponse
    {
        $this->screen($request, ProjectsScreen::canApprove());
        $d = $request->validate(['ids' => ['required', 'array', 'min:1'], 'ids.*' => ['uuid'], 'action' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:255']], ['ids.*' => 'Choose the time to approve.', 'reason.*' => 'Say what to correct.']);
        $entries = TimeApprovals::waiting()->whereIn('time_entries.id', $d['ids'])->get();
        abort_if($entries->isEmpty(), 422, 'That time is no longer waiting.');

        return $this->act(fn (Actor $actor) => ['message' => $d['action'] === 'approve'
            ? $sheets->approve($actor, $entries).' entr(y/ies) approved.'
            : $sheets->reject($actor, $entries, $d['reason']).' entr(y/ies) sent back.']);
    }

    // -------------------------------------------------------------------------------------------- Hourly rates

    public function rates(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, ProjectsScreen::canRates())->rates());
    }

    public function rateSave(Request $request, string $user, Projects $projects): JsonResponse
    {
        $this->screen($request, ProjectsScreen::canRates());
        $u = User::where('is_active', true)->findOrFail($user);
        abort_unless(app(Authorizer::class)->allowsAnywhere($u, 'projects.time'), 404);
        $d = $request->validate(['cost' => ['required', 'numeric', 'min:0'], 'bill' => ['nullable', 'numeric', 'min:0']], ['cost.*' => 'Enter what an hour costs the company.']);

        return $this->act(fn (Actor $actor) => ['message' => $projects->saveRate($actor, $u, FinanceUi::toMinor($d['cost']), self::minor($d['bill'] ?? null)) ? 'Rates saved.' : '']);
    }
}
