<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Projects\Projects;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectMilestone;
use App\Models\ProjectRate;
use App\Models\TimeEntry;
use App\Models\Warehouse;
use App\Security\ScopeType;

// Projects in the new back office (part 27): projects with tasks, milestones and costs, My time, Time to approve, Hourly rates.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    activateTestLicense(['features' => ['finance', 'hr', 'projects']]);
    $this->hq = $this->b['HQ'];
    withMfa($this->owner);
    $this->store = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->customer = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ridge Clinic', 'phone' => '0302000111']);
    $this->service = makeProduct($this->owner, 'SVC', 'Professional services', retail: '0.00');
    $this->service->forceFill(['track_stock' => false])->save();
    $this->cable = makeProduct($this->owner, 'CBL', 'Cable (metre)', retail: '15.00', tax: 'EXEMPT');
    $this->metre = $this->cable->units->firstWhere('is_base', true);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->store, $this->cable, $this->metre, '100', 'opening', '', unitCost: '10.00');
    $this->tech = staff($this->owner, 'kofi', 2, 'technician', ScopeType::Branch, $this->hq->id);
    app(Projects::class)->saveRate(actorFor($this->owner), $this->tech, 2000, null);
});

it('lists, adds and changes projects, with tasks, stock, invoicing time and closing', function () {
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson('/office-api/projects/form?customer='.$this->customer->id)->assertOk()->json();
    expect($form['values']['customer_id'])->toBe($this->customer->id)->and($form['values']['customer_name'])->toBe('RIDGE CLINIC')
        ->and($form['actions']['edit'])->toBeTrue()->and(collect($form['branches'])->pluck('id'))->toContain($this->hq->id);
    $base = ['name' => 'Clinic network', 'billing' => 'time', 'customer_id' => $this->customer->id, 'branch_id' => $this->hq->id, 'hourly_rate' => '100',
        'markup' => '10', 'budget_cost' => '500'];
    $this->postJson('/office-api/projects', $base)->assertStatus(422)->assertJsonPath('error', fn ($e) => $e !== null); // no service on invoices
    $id = $this->postJson('/office-api/projects', $base + ['service_product_id' => $this->service->id])->assertOk()->json('id');
    $p = Project::find($id);
    expect($p->hourly_rate_minor)->toBe(10000)->and($p->materials_markup_bp)->toBe(1000)->and($p->budget_cost_minor)->toBe(50000)->and($p->status)->toBe('active');

    $list = $this->getJson('/office-api/projects')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['rows'][0])->toMatchArray(['number' => $p->number, 'customer' => 'RIDGE CLINIC', 'billing' => 'Time & materials', 'status' => 'active']);
    expect($this->getJson('/office-api/projects?status=done')->json('total'))->toBe(0);

    // A task for the technician, and the cable issued from the store.
    $this->postJson("/office-api/projects/{$id}/tasks", ['name' => 'Pull cables', 'assigned_to' => $this->tech->id, 'status' => 'todo', 'estimate_hours' => '6', 'billable' => true])->assertOk();
    $units = $this->getJson('/office-api/projects/units?q=cable')->assertOk()->json('results');
    expect(collect($units)->pluck('id'))->toContain($this->metre->id);
    $this->postJson("/office-api/projects/{$id}/action", ['action' => 'issue', 'warehouse_id' => $this->store->id, 'lines' => [['product_uom_id' => $this->metre->id, 'qty' => '20']]])->assertOk();

    $one = $this->getJson("/office-api/projects/{$id}")->assertOk()->json();
    expect($one['tasks'][0])->toMatchArray(['name' => 'Pull cables', 'who' => 'Kofi', 'status' => 'todo'])
        ->and($one['figures']['cost'])->toBe(20000)->and($one['figures']['unbilled'])->toBe(22000) // 20 m × GHS 10 + 10 %
        ->and($one['costs'][0])->toMatchArray(['kind' => 'material', 'cost' => 20000, 'bill' => 22000, 'unbilled' => true])
        ->and($one['actions'])->toMatchArray(['return' => true, 'invoice_time' => true, 'finish' => true, 'reopen' => false]);

    // Changing it keeps how it is billed once there are costs.
    $this->postJson("/office-api/projects/{$id}", ['billing' => 'internal'] + $base + ['service_product_id' => $this->service->id])->assertStatus(422);
    $this->postJson("/office-api/projects/{$id}", ['status' => 'on_hold', 'name' => 'Clinic network (phase 1)'] + $base + ['service_product_id' => $this->service->id])->assertOk();
    expect(Project::find($id)->only(['name', 'status']))->toBe(['name' => 'Clinic network (phase 1)', 'status' => 'on_hold']);

    $open = $this->postJson("/office-api/projects/{$id}/action", ['action' => 'invoice_time'])->assertOk()->json('open');
    $invoice = Invoice::where('project_id', $id)->sole();
    expect($open)->toBe("/office/invoices/{$invoice->id}")->and(ProjectCost::where('project_id', $id)->whereNull('invoice_id')->count())->toBe(0);

    $this->postJson("/office-api/projects/{$id}/action", ['action' => 'finish'])->assertOk();
    expect(Project::find($id)->status)->toBe('done');
    expect($this->getJson("/office-api/projects/{$id}")->json('actions'))->toMatchArray(['edit' => false, 'reopen' => true, 'issue' => false]);
    $this->postJson("/office-api/projects/{$id}/tasks", ['name' => 'Late task', 'status' => 'todo'])->assertStatus(422); // closed
    $this->postJson("/office-api/projects/{$id}/action", ['action' => 'reopen'])->assertOk();
    expect(Project::find($id)->status)->toBe('active');
});

it('keeps a fixed-price project\'s milestones and invoices one', function () {
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/projects', ['name' => 'Clinic fit-out', 'billing' => 'milestones', 'customer_id' => $this->customer->id, 'branch_id' => $this->hq->id,
        'contract' => '1000', 'service_product_id' => $this->service->id])->assertOk()->json('id');
    $this->postJson("/office-api/projects/{$id}/milestones", ['name' => 'Deposit', 'amount' => '300', 'status' => 'ready'])->assertOk();
    $this->postJson("/office-api/projects/{$id}/milestones", ['name' => 'Too much', 'amount' => '800', 'status' => 'pending'])->assertStatus(422);
    $m = ProjectMilestone::where('project_id', $id)->sole();
    $this->postJson("/office-api/projects/{$id}/milestones/{$m->id}", ['name' => 'Deposit (30 %)', 'amount' => '300', 'status' => 'ready'])->assertOk();
    $one = $this->getJson("/office-api/projects/{$id}")->assertOk()->json();
    expect($one['milestones'][0])->toMatchArray(['name' => 'Deposit (30 %)', 'amount' => 30000, 'status' => 'ready', 'can_invoice' => true])
        ->and($one['actions']['invoice_time'])->toBeFalse()->and($one['actions']['milestones'])->toBeTrue();
    $open = $this->postJson("/office-api/projects/{$id}/milestones/{$m->id}/invoice")->assertOk()->json('open');
    expect($open)->toStartWith('/office/invoices/')->and($m->fresh()->status)->toBe('invoiced');
    $this->postJson("/office-api/projects/{$id}/milestones/{$m->id}", ['name' => 'Deposit', 'amount' => '200', 'status' => 'ready'])->assertStatus(422); // invoiced
});

it('logs time, runs the timer, sends the week, sends back and approves, and keeps hourly rates', function () {
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/projects', ['name' => 'Clinic network', 'billing' => 'time', 'customer_id' => $this->customer->id, 'branch_id' => $this->hq->id,
        'hourly_rate' => '100', 'service_product_id' => $this->service->id])->assertOk()->json('id');
    $task = $this->postJson("/office-api/projects/{$id}/tasks", ['name' => 'Pull cables', 'status' => 'todo'])->assertOk()->json('id');

    // The technician's week.
    $this->actingAs($this->tech, 'web');
    $this->getJson('/office-api/projects')->assertForbidden(); // logs time, does not see the projects pages
    $week = $this->getJson('/office-api/my-time')->assertOk()->json();
    expect(collect($week['projects'])->pluck('id'))->toContain($id)->and($week['rows'])->toBe([])->and($week['can_submit'])->toBeFalse();
    expect($this->getJson("/office-api/my-time/tasks?project={$id}")->assertOk()->json('tasks.0.id'))->toBe($task);
    $this->postJson('/office-api/my-time', ['project_id' => $id, 'work_date' => now()->addDay()->toDateString(), 'hours' => '2'])->assertStatus(422); // a day to come
    $e = $this->postJson('/office-api/my-time', ['project_id' => $id, 'task_id' => $task, 'work_date' => now()->toDateString(), 'hours' => '2', 'note' => 'Ground floor'])->assertOk()->json('id');
    $this->postJson("/office-api/my-time/{$e}", ['project_id' => $id, 'task_id' => $task, 'work_date' => now()->toDateString(), 'hours' => '3', 'note' => 'Ground floor'])->assertOk();
    expect(TimeEntry::find($e)->minutes)->toBe(180);
    $this->postJson('/office-api/my-time/timer', ['action' => 'start', 'project_id' => $id])->assertOk();
    expect($this->getJson('/office-api/my-time')->json('running.project'))->toBe('Clinic network');
    $this->postJson('/office-api/my-time/timer', ['action' => 'stop'])->assertOk();
    expect($this->getJson('/office-api/my-time')->json('running'))->toBeNull();
    TimeEntry::where('user_id', $this->tech->id)->whereKeyNot($e)->delete(); // the timer's minute, kept out of the sums below
    $this->postJson('/office-api/my-time/timer', ['action' => 'submit', 'week' => now()->toDateString()])->assertOk();
    expect(TimeEntry::find($e)->status)->toBe('submitted');
    $this->postJson("/office-api/my-time/{$e}/remove")->assertStatus(422); // sent
    $this->getJson('/office-api/time-approvals')->assertForbidden();

    // The owner sends it back, the technician corrects and sends it again, the owner approves.
    $this->actingAs($this->owner, 'web');
    $wait = $this->getJson('/office-api/time-approvals')->assertOk()->json('people');
    expect($wait)->toHaveCount(1)->and($wait[0])->toMatchArray(['name' => 'Kofi', 'total' => TimeEntry::hours(180)])->and($wait[0]['rows'][0]['id'])->toBe($e);
    $this->postJson('/office-api/time-approvals', ['ids' => [$e], 'action' => 'reject'])->assertStatus(422);
    $this->postJson('/office-api/time-approvals', ['ids' => [$e], 'action' => 'reject', 'reason' => 'Two and a half hours'])->assertOk();
    $this->actingAs($this->tech, 'web');
    expect($this->getJson('/office-api/my-time')->json('rows.0'))->toMatchArray(['status' => 'rejected', 'reason' => 'Two and a half hours', 'can_change' => true]);
    $this->postJson("/office-api/my-time/{$e}", ['project_id' => $id, 'task_id' => $task, 'work_date' => now()->toDateString(), 'hours' => '2.5'])->assertOk();
    $this->postJson('/office-api/my-time/timer', ['action' => 'submit', 'week' => now()->toDateString()])->assertOk();
    $this->actingAs($this->owner, 'web');
    $this->postJson('/office-api/time-approvals', ['ids' => [$e], 'action' => 'approve'])->assertOk();
    expect(TimeEntry::find($e)->status)->toBe('approved')
        ->and(ProjectCost::where('project_id', $id)->where('kind', 'time')->sole()->cost_minor)->toBe(5000); // 2.5 h × GHS 20
    expect($this->getJson('/office-api/time-approvals')->json('people'))->toBe([]);

    // Hourly rates.
    $rates = collect($this->getJson('/office-api/project-rates')->assertOk()->json('rows'))->keyBy('id');
    expect($rates[$this->tech->id])->toMatchArray(['name' => 'Kofi', 'cost' => 2000, 'bill' => null]);
    $this->postJson("/office-api/project-rates/{$this->tech->id}", ['cost' => '25', 'bill' => '120'])->assertOk();
    expect(ProjectRate::where('user_id', $this->tech->id)->sole()->only(['cost_rate_minor', 'bill_rate_minor']))->toBe(['cost_rate_minor' => 2500, 'bill_rate_minor' => 12000]);
    $this->actingAs($this->tech, 'web');
    $this->getJson('/office-api/project-rates')->assertForbidden();
});
