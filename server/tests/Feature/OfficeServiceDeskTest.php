<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Service\Maintenance;
use App\Models\Customer;
use App\Models\MaintenanceAsset;
use App\Models\MaintenanceOrder;
use App\Models\Message;
use App\Models\ServiceLevel;
use App\Models\Ticket;
use App\Security\ScopeType;

// The service desk in the new back office (part 28): tickets (list, new, one with its conversation and actions) and deadlines.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    activateTestLicense(['features' => ['finance', 'hr', 'service']]);
    $this->hq = $this->b['HQ'];
    withMfa($this->owner);
    $this->agent = staff($this->owner, 'efua', 2, 'customer_service', ScopeType::Branch, $this->hq->id);
    $this->customer = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama Darko', 'phone' => '0244000111', 'email' => 'ama@example.com']);
});

it('opens a ticket, lists it, replies, notes, passes it on, resolves and reopens it', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    $form = $this->getJson('/office-api/tickets/form')->assertOk()->json();
    expect($form['staff_only'])->toBeFalse()->and(collect($form['kinds'])->pluck('id'))->toContain('complaint')
        ->and(collect($form['people'])->pluck('id'))->toContain($this->agent->id);
    $this->postJson('/office-api/tickets', ['kind' => 'complaint', 'priority' => 'high', 'subject' => 'Kettle stopped working'])->assertStatus(422); // nobody to answer
    $id = $this->postJson('/office-api/tickets', ['kind' => 'complaint', 'priority' => 'high', 'source' => 'phone', 'subject' => 'Kettle stopped working',
        'description' => 'Bought last week.', 'customer_id' => $this->customer->id])->assertOk()->json('id');
    $t = Ticket::find($id);
    expect($t->only(['kind', 'priority', 'source', 'status']))->toBe(['kind' => 'complaint', 'priority' => 'high', 'source' => 'phone', 'status' => 'new'])
        ->and($t->respond_by)->not->toBeNull();

    $list = $this->getJson('/office-api/tickets')->assertOk()->json();
    expect($list['total'])->toBe(1)->and($list['title'])->toBe('Tickets')->and($list['create_label'])->toBe('New ticket')
        ->and($list['rows'][0])->toMatchArray(['number' => $t->number, 'subject' => 'Kettle stopped working', 'priority' => 'High', 'status_label' => 'New', 'due_what' => 'to answer']);
    expect($this->getJson('/office-api/tickets?open=0')->json('total'))->toBe(0)->and($this->getJson('/office-api/tickets?mine=1')->json('total'))->toBe(0);

    $one = $this->getJson("/office-api/tickets/{$id}")->assertOk()->json();
    expect($one['actions'])->toMatchArray(['reply' => true, 'note' => true, 'assign' => true, 'resolve' => true, 'reopen' => false])
        ->and(collect($one['channels'])->pluck('id')->all())->toBe(['whatsapp', 'sms', 'email'])
        ->and($one['messages'][0])->toMatchArray(['direction' => 'in', 'body' => 'Bought last week.']);

    $this->postJson("/office-api/tickets/{$id}/action", ['action' => 'reply', 'channel' => 'sms', 'body' => 'Please bring it with the receipt.', 'wait' => true])->assertOk();
    expect(Ticket::find($id)->status)->toBe('waiting')->and(Message::where('reference', $t->number)->sole()->channel)->toBe('sms');
    $this->postJson("/office-api/tickets/{$id}/action", ['action' => 'note', 'body' => 'Called twice.'])->assertOk();
    $this->postJson("/office-api/tickets/{$id}/action", ['action' => 'assign', 'assigned_to' => $this->agent->id, 'priority' => 'urgent'])->assertOk();
    expect(Ticket::find($id)->only(['assigned_to', 'priority']))->toBe(['assigned_to' => $this->agent->id, 'priority' => 'urgent']);
    $this->postJson("/office-api/tickets/{$id}/action", ['action' => 'resolve'])->assertStatus(422);
    $this->postJson("/office-api/tickets/{$id}/action", ['action' => 'resolve', 'resolution' => 'Replaced the kettle.'])->assertOk();
    $one = $this->getJson("/office-api/tickets/{$id}")->json();
    expect($one['status'])->toBe('resolved')->and($one['resolution'])->toBe('Replaced the kettle.')->and($one['fix']['done'])->toBeTrue()
        ->and(collect($one['messages'])->pluck('direction')->all())->toContain('out', 'note')->and($one['actions']['reopen'])->toBeTrue();
    $this->postJson("/office-api/tickets/{$id}/action", ['action' => 'reopen'])->assertOk();
    expect(Ticket::find($id)->isOpen())->toBeTrue();

    // A work order for the equipment it is about.
    $fridge = app(Maintenance::class)->saveAsset(actorFor($this->owner), new MaintenanceAsset, ['name' => 'Vaccine fridge', 'category' => 'fridge', 'branch_id' => $this->hq->id]);
    $open = $this->postJson("/office-api/tickets/{$id}/action", ['action' => 'work_order', 'asset_id' => $fridge->id])->assertOk()->json('open');
    expect($open)->toBe('/office/maintenance/orders/'.MaintenanceOrder::where('asset_id', $fridge->id)->sole()->id);
});

it('lets staff who only ask for help raise their own requests, and see only those', function () {
    $cashier = staff($this->owner, 'yaa', 3, 'cashier', ScopeType::Branch, $this->hq->id);
    $this->actingAs(withMfa($this->owner), 'web');
    $this->postJson('/office-api/tickets', ['kind' => 'support', 'priority' => 'normal', 'subject' => 'Someone else', 'customer_id' => $this->customer->id])->assertOk();

    $this->actingAs(withMfa($cashier), 'web');
    $form = $this->getJson('/office-api/tickets/form')->assertOk()->json();
    expect($form['staff_only'])->toBeTrue()->and(collect($form['kinds'])->pluck('id')->all())->toBe(['internal'])->and($form['people'])->toBe([]);
    // Asking for a customer complaint and giving it to someone is not theirs to do: it is opened as their own request.
    $id = $this->postJson('/office-api/tickets', ['kind' => 'complaint', 'priority' => 'normal', 'subject' => 'Receipt printer jams', 'assigned_to' => $this->agent->id])->assertOk()->json('id');
    expect(Ticket::find($id)->only(['kind', 'source', 'raised_by', 'assigned_to']))->toBe(['kind' => 'internal', 'source' => 'staff', 'raised_by' => $cashier->id, 'assigned_to' => null]);
    $list = $this->getJson('/office-api/tickets')->assertOk()->json();
    expect($list['title'])->toBe('My requests')->and($list['create_label'])->toBe('Ask for help')->and($list['total'])->toBe(1)->and($list['rows'][0]['id'])->toBe($id);
    $one = $this->getJson("/office-api/tickets/{$id}")->assertOk()->json();
    expect($one['actions'])->toMatchArray(['reply' => false, 'note' => true, 'assign' => false, 'resolve' => false]);
    $this->postJson("/office-api/tickets/{$id}/action", ['action' => 'resolve', 'resolution' => 'Fixed it myself'])->assertForbidden();
    $this->getJson('/office-api/tickets/customers?q=ama')->assertForbidden();
    $this->getJson('/office-api/service-levels')->assertForbidden();
});

it('changes the deadlines for each priority', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    $rows = $this->getJson('/office-api/service-levels')->assertOk()->json('rows');
    expect(collect($rows)->pluck('priority')->all())->toBe(['Urgent', 'High', 'Normal', 'Low']);
    $urgent = ServiceLevel::where('priority', 'urgent')->sole();
    $this->postJson("/office-api/service-levels/{$urgent->id}", ['respond' => '0.1', 'resolve' => '2'])->assertStatus(422);
    $this->postJson("/office-api/service-levels/{$urgent->id}", ['respond' => '0.5', 'resolve' => '3'])->assertOk();
    expect($urgent->fresh()->only(['respond_minutes', 'resolve_minutes']))->toBe(['respond_minutes' => 30, 'resolve_minutes' => 180]);
});
