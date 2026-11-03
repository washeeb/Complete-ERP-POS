<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Expenses\ExpenseService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Inventory\InventoryService;
use App\Domain\Payables\BillService;
use App\Domain\Projects\Projects;
use App\Domain\Projects\Timesheets;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\MyTime;
use App\Filament\Pages\ProjectRates;
use App\Filament\Pages\TimeApprovals;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Widgets\Panels\ProjectsPanel;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectBilling;
use App\Models\ProjectCost;
use App\Models\ProjectMilestone;
use App\Models\ProjectTask;
use App\Models\Supplier;
use App\Models\TimeEntry;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    activateTestLicense(['features' => ['finance', 'hr', 'projects']]);
    $this->hq = $this->branches['HQ'];
    $this->store = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->projects = app(Projects::class);
    $this->sheets = app(Timesheets::class);
    $this->customer = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ridge Clinic', 'phone' => '0302000111']);
    // The service the invoices show: not a stock item, standard rated.
    $this->service = makeProduct($this->owner, 'SVC', 'Professional services', retail: '0.00');
    $this->service->forceFill(['track_stock' => false])->save();
    // Cable at GHS 10.00 a metre in the store.
    $this->cable = makeProduct($this->owner, 'CBL', 'Cable (metre)', retail: '15.00', tax: 'EXEMPT');
    $this->metre = $this->cable->units->firstWhere('is_base', true);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->store, $this->cable, $this->metre, '100', 'opening', '', unitCost: '10.00');
    // A technician logs time at the head office; costs the company GHS 20.00 an hour.
    $this->tech = staff($this->owner, 'kofi', 2, 'technician', ScopeType::Branch, $this->hq->id);
    $this->projects->saveRate(actorFor($this->owner), $this->tech, 2000, null);
});

function project($t, array $d = []): Project
{
    return app(Projects::class)->save(actorFor($t->owner), new Project, $d + ['name' => 'Clinic network', 'customer_id' => $t->customer->id, 'branch_id' => $t->hq->id,
        'billing' => 'time', 'hourly_rate_minor' => 10000, 'materials_markup_bp' => 1000, 'service_product_id' => $t->service->id, 'budget_cost_minor' => 50000]);
}

function account(string $key): string
{
    return Account::where('system_key', $key)->value('id');
}

function projectBalance(string $key, string $projectId): int
{
    return (int) DB::table('journal_lines')->where('account_id', account($key))->where('project_id', $projectId)->sum('amount_minor');
}

it('is a paid add-on', function () {
    activateTestLicense(['features' => ['finance', 'hr']]);

    expect(fn () => project($this))->toThrow(DomainException::class, 'paid add-on');
    $this->actingAs(withMfa($this->owner), 'web')->get(ProjectResource::getUrl())->assertForbidden();
});

it('checks a project before it starts', function () {
    expect(fn () => project($this, ['customer_id' => null]))->toThrow(DomainException::class, 'needs its customer')
        ->and(fn () => project($this, ['service_product_id' => $this->cable->id]))->toThrow(DomainException::class, 'stock item')
        ->and(fn () => app(Projects::class)->save(actorFor($this->tech), new Project, ['name' => 'X', 'billing' => 'internal', 'branch_id' => $this->hq->id]))->toThrow(AccessDenied::class);

    $p = project($this);
    expect($p)->number->toMatch('/^PRJ-/')->status->toBe('active')->currency_code->toBe('GHS');
});

it('collects a time-and-materials job\'s costs by themselves and invoices them, the books following', function () {
    $p = project($this);
    $task = $this->projects->saveTask(actorFor($this->owner), $p, new ProjectTask, ['name' => 'Pull cables', 'assigned_to' => $this->tech->id, 'estimate_hours' => 8]);

    // Materials: 5 m out, 1 m back.
    $this->projects->issue(actorFor($this->owner), $p, $this->store, [['product_uom_id' => $this->metre->id, 'qty' => '5']], 'Ward A');
    $this->projects->issue(actorFor($this->owner), $p, $this->store, [['product_uom_id' => $this->metre->id, 'qty' => '1']], null, return: true);
    expect(ProjectCost::where('kind', 'material')->orderBy('created_at')->get(['cost_minor', 'bill_minor'])->map->only(['cost_minor', 'bill_minor'])->all())
        ->toBe([['cost_minor' => 5000, 'bill_minor' => 5500], ['cost_minor' => -1000, 'bill_minor' => -1100]]);

    // Bought in: a supplier bill line charged to the project.
    $supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Tema Electricals']);
    $repairs = Account::where('type', 'expense')->where('is_header', false)->whereNull('system_key')->value('id');
    app(BillService::class)->create(actorFor($this->owner), $supplier, $this->hq, ['supplier_invoice_no' => 'TE-77', 'bill_date' => now()->toDateString(),
        'lines' => [['account_id' => $repairs, 'description' => 'Cable tester hire', 'amount_minor' => 3000, 'project_id' => $p->id]]]);

    // An expense claim charged to it, paid.
    $claims = app(ExpenseService::class);
    $sup = staff($this->owner, 'ama', 3, 'supervisor', ScopeType::Branch, $this->hq->id);
    $claim = $claims->submit(actorFor($sup), $this->hq, ['account_id' => $repairs, 'description' => 'Taxi to the clinic', 'amount_minor' => 2000, 'project_id' => $p->id]);
    $claims->approve(actorFor($this->owner), $claim);
    expect(ProjectCost::where('kind', 'expense')->exists())->toBeFalse(); // counted when paid
    $claims->pay(actorFor($this->owner), $claim->fresh(), Account::where('system_key', 'cash_office')->firstOrFail());

    // Time: 2½ hours logged, sent, approved by the manager.
    $this->sheets->log(actorFor($this->tech), new TimeEntry, ['project_id' => $p->id, 'task_id' => $task->id, 'work_date' => now()->toDateString(), 'hours' => '2.5']);
    $this->sheets->submitWeek(actorFor($this->tech), now()->toDateString());
    $this->sheets->approve(actorFor($this->owner), TimeEntry::where('status', 'submitted')->get());
    expect(ProjectCost::where('kind', 'time')->sole())->cost_minor->toBe(5000)->bill_minor->toBe(25000)->in_books->toBeFalse();

    $f = $this->projects->figures($p->fresh());
    expect($f)->cost->toBe(4000 + 3000 + 2000 + 5000)->unbilled->toBe(4400 + 3300 + 2200 + 25000)->hours->toBe(2.5);

    // One invoice: time, the cable (net of what came back) and the bought-in costs, before tax.
    $invoice = $this->projects->invoiceTime(actorFor($this->owner), $p->fresh());
    expect($invoice)->project_id->toBe($p->id)->customer_ref->toBe($p->number)->net_minor->toBe(34900)
        ->and($invoice->lines()->orderBy('line_no')->pluck('net_minor')->all())->toBe([25000, 4400, 3300, 2200])
        ->and($invoice->lines()->orderBy('line_no')->value('name'))->toBe('Clinic network: 2.5 hour(s)')
        ->and($this->projects->unbilled($p))->toHaveCount(0)
        ->and(ProjectBilling::sole())->released_minor->toBe(4000 + 3000 + 2000)->reason->toBe('invoice');

    app(AutoPoster::class)->run($this->tenant->id);
    expect(projectBalance('project_wip', $p->id))->toBe(0)                 // in, then out when invoiced
        ->and(projectBalance('project_costs', $p->id))->toBe(9000)
        ->and(projectBalance('sales_projects', $p->id))->toBe(-34900)
        ->and(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('invoices a fixed-price job by milestones and moves its costs to cost as it goes and when it closes', function () {
    $p = project($this, ['billing' => 'milestones', 'contract_minor' => 1000000, 'hourly_rate_minor' => null]);
    $deposit = $this->projects->saveMilestone(actorFor($this->owner), $p, new ProjectMilestone, ['name' => 'Deposit', 'amount_minor' => 300000]);
    $this->projects->saveMilestone(actorFor($this->owner), $p, new ProjectMilestone, ['name' => 'Handover', 'amount_minor' => 700000]);
    expect(fn () => $this->projects->saveMilestone(actorFor($this->owner), $p, new ProjectMilestone, ['name' => 'Extra', 'amount_minor' => 1]))
        ->toThrow(DomainException::class, 'more than the agreed price');

    $this->projects->issue(actorFor($this->owner), $p, $this->store, [['product_uom_id' => $this->metre->id, 'qty' => '3']]);
    $invoice = $this->projects->invoiceMilestone(actorFor($this->owner), $deposit);
    expect($invoice->net_minor)->toBe(300000)->and($deposit->fresh())->status->toBe('invoiced')->invoice_id->toBe($invoice->id)
        ->and((int) ProjectBilling::sum('released_minor'))->toBe(3000)
        ->and(fn () => $this->projects->invoiceMilestone(actorFor($this->owner), $deposit->fresh()))->toThrow(DomainException::class, 'already invoiced');

    $this->projects->issue(actorFor($this->owner), $p, $this->store, [['product_uom_id' => $this->metre->id, 'qty' => '2']]);
    $this->projects->close(actorFor($this->owner), $p->fresh());
    expect($p->fresh()->status)->toBe('done')->and(ProjectBilling::where('reason', 'close')->sole()->released_minor)->toBe(2000)
        ->and(fn () => $this->projects->issue(actorFor($this->owner), $p->fresh(), $this->store, [['product_uom_id' => $this->metre->id, 'qty' => '1']]))->toThrow(DomainException::class, 'closed');

    app(AutoPoster::class)->run($this->tenant->id);
    expect(projectBalance('project_wip', $p->id))->toBe(0)->and(projectBalance('project_costs', $p->id))->toBe(5000);
});

it('charges an internal project\'s costs straight to project costs and never invoices it', function () {
    $p = project($this, ['billing' => 'internal', 'customer_id' => null, 'service_product_id' => null, 'name' => 'New store fit-out']);
    $this->projects->issue(actorFor($this->owner), $p, $this->store, [['product_uom_id' => $this->metre->id, 'qty' => '4']]);

    expect(ProjectCost::sole())->billable->toBeFalse()->bill_minor->toBe(0)
        ->and(fn () => $this->projects->invoiceTime(actorFor($this->owner), $p))->toThrow(DomainException::class, 'Only time-and-materials');
    app(AutoPoster::class)->run($this->tenant->id);
    expect(projectBalance('project_costs', $p->id))->toBe(4000)->and(projectBalance('project_wip', $p->id))->toBe(0);
});

it('keeps timesheets honest: no future days, no more than 24 hours, approved by someone else, corrected when sent back', function () {
    $p = project($this);
    $log = fn (array $d) => $this->sheets->log(actorFor($this->tech), new TimeEntry, $d + ['project_id' => $p->id, 'work_date' => now()->toDateString()]);

    expect(fn () => $log(['hours' => '25']))->toThrow(DomainException::class, 'at most 24')
        ->and(fn () => $log(['hours' => '1', 'work_date' => now()->addDay()->toDateString()]))->toThrow(DomainException::class, 'still to come');
    $log(['hours' => '20']);
    expect(fn () => $log(['hours' => '5']))->toThrow(DomainException::class, 'more than 24 hours');

    // The timer: started, stopped, at least a minute.
    $this->freezeTime();
    $timer = $this->sheets->start(actorFor($this->tech), $p);
    $this->travel(30)->minutes();
    expect($this->sheets->stop(actorFor($this->tech)))->minutes->toBe(30)->timer_started_at->toBeNull();

    $this->sheets->submitWeek(actorFor($this->tech), now()->toDateString());
    $sent = TimeEntry::where('status', 'submitted')->get();
    expect($sent)->toHaveCount(2)
        ->and(fn () => $this->sheets->log(actorFor($this->tech), $sent->first(), ['hours' => '1']))->toThrow(DomainException::class, 'not sent yet')
        ->and(fn () => $this->sheets->approve(actorFor($this->tech), $sent))->toThrow(AccessDenied::class);

    // The owner's own time is approved by someone else.
    $this->sheets->log(actorFor($this->owner), new TimeEntry, ['project_id' => $p->id, 'work_date' => now()->toDateString(), 'hours' => '1']);
    $this->sheets->submitWeek(actorFor($this->owner), now()->toDateString());
    expect(fn () => $this->sheets->approve(actorFor($this->owner), TimeEntry::where('user_id', $this->owner->id)->get()))->toThrow(DomainException::class, 'someone else');

    expect(fn () => $this->sheets->reject(actorFor($this->owner), $sent, ''))->toThrow(DomainException::class, 'what to correct');
    $this->sheets->reject(actorFor($this->owner), $sent, 'Split the 20 hours by day');
    expect($timer->fresh())->status->toBe('rejected')->reject_reason->toBe('Split the 20 hours by day')
        ->and($this->sheets->log(actorFor($this->tech), $timer->fresh(), ['hours' => '0.75'])->status)->toBe('draft')
        ->and(ProjectCost::count())->toBe(0);
});

it('shows the project pages to the people with the rights', function () {
    $p = project($this);
    $this->projects->saveTask(actorFor($this->owner), $p, new ProjectTask, ['name' => 'Pull cables']);
    $owner = withMfa($this->owner);

    $this->actingAs($owner, 'web')->get(ProjectResource::getUrl())->assertOk()->assertSee('Clinic network');
    $this->actingAs($owner, 'web')->get(ProjectResource::getUrl('create'))->assertOk()->assertSee('How it is billed');
    $this->actingAs($owner, 'web')->get(ProjectResource::getUrl('edit', ['record' => $p]))->assertOk()->assertSee('How it is doing')->assertSee('Issue stock');
    $this->actingAs($owner, 'web')->get(TimeApprovals::getUrl())->assertOk();
    $this->actingAs($owner, 'web')->get(ProjectRates::getUrl())->assertOk()->assertSee($this->tech->name);
    Livewire::actingAs($owner)->test(ProjectsPanel::class)->assertSee('Projects in progress');
});

it('lets a technician log time but not run projects', function () {
    $p = project($this);
    $tech = withMfa($this->tech);

    $this->actingAs($tech, 'web')->get(MyTime::getUrl())->assertOk()->assertSee('Send this week', false);
    Livewire::actingAs($tech)->test(MyTime::class)
        ->callAction('start', ['project_id' => $p->id])->assertHasNoActionErrors();
    expect(TimeEntry::whereNotNull('timer_started_at')->where('user_id', $this->tech->id)->exists())->toBeTrue();
});

it('keeps projects from people without the rights', function () {
    $keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->hq->id);

    $this->actingAs(withMfa($keeper), 'web')->get(ProjectResource::getUrl())->assertForbidden();
});
