<?php

use App\Models\CrmActivity;
use App\Models\CrmCampaign;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Models\Customer;
use App\Security\ScopeType;

// CRM in the new back office (part 25): leads, deals, pipeline, follow-ups, campaigns and pipeline stages.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    activateTestLicense(['features' => ['finance', 'hr', 'crm']]);
    $this->agent = withMfa(staff($this->owner, 'efua', 2, 'customer_service', ScopeType::Branch, $this->b['HQ']->id));
    withMfa($this->owner);
});

it('adds a lead, plans a follow-up, makes them a customer with a deal, and closes another as lost', function () {
    $this->actingAs($this->agent, 'web');
    $form = $this->getJson('/office-api/crm/leads/form')->assertOk()->json();
    expect($form['values']['branch_id'])->toBe($this->b['HQ']->id)->and($form['sources'])->not->toBeEmpty();
    $this->postJson('/office-api/crm/leads', ['name' => 'Kofi', 'source' => 'whatsapp', 'status' => 'new', 'branch_id' => $this->b['HQ']->id])->assertStatus(422); // a phone or e-mail
    $id = $this->postJson('/office-api/crm/leads', ['name' => 'Kofi Mensah', 'company_name' => 'Mensah Clinic', 'phone' => '024 455 1234', 'source' => 'whatsapp', 'status' => 'new',
        'interest' => 'Monthly gloves', 'value' => '500', 'branch_id' => $this->b['HQ']->id, 'consents' => ['sms' => true]])->assertOk()->json('id');
    $l = CrmLead::find($id);
    expect($l->phone)->toStartWith('+233')->and($l->value_minor)->toBe(50000)->and($l->consents['sms']['granted'] ?? false)->toBeTrue();
    expect($this->getJson('/office-api/crm/leads?q=mensah')->json('rows.0'))->toMatchArray(['name' => 'Kofi Mensah', 'status' => 'new', 'worth' => 50000]);

    $this->postJson('/office-api/crm/activities', ['type' => 'call', 'subject' => 'Call back about the price', 'due_at' => now()->addDay()->format('Y-m-d H:i'), 'lead_id' => $id])->assertOk();
    $one = $this->getJson("/office-api/crm/leads/{$id}")->assertOk()->json();
    expect($one['activities'][0])->toMatchArray(['subject' => 'Call back about the price', 'who' => $this->agent->name, 'can_do' => true]);
    $fu = $this->getJson('/office-api/crm/follow-ups')->assertOk()->json();
    expect($fu['total'])->toBe(1)->and($fu['rows'][0]['open'])->toBe("/office/crm/leads/{$id}");
    $this->postJson("/office-api/crm/activities/{$fu['rows'][0]['id']}/action", ['action' => 'move', 'due_at' => now()->addDays(3)->format('Y-m-d H:i')])->assertOk();
    $this->postJson("/office-api/crm/activities/{$fu['rows'][0]['id']}/action", ['action' => 'done', 'outcome' => 'Wants a quote'])->assertOk();
    expect(CrmActivity::sole()->outcome)->toBe('Wants a quote')->and($this->getJson('/office-api/crm/follow-ups')->json('total'))->toBe(0);

    $this->actingAs($this->owner, 'web'); // making a customer needs customers.manage
    $conv = $this->postJson("/office-api/crm/leads/{$id}/action", ['action' => 'convert', 'deal' => 'Monthly gloves'])->assertOk()->json();
    $deal = CrmDeal::sole();
    expect($conv['open'])->toBe("/office/crm/deals/{$deal->id}")->and(CrmLead::find($id)->status)->toBe('converted')->and($deal->customer_id)->not->toBeNull();

    $other = $this->postJson('/office-api/crm/leads', ['name' => 'Ama', 'phone' => '0201112222', 'source' => 'walk_in', 'status' => 'new', 'branch_id' => $this->b['HQ']->id])->json('id');
    $this->postJson("/office-api/crm/leads/{$other}/action", ['action' => 'lose'])->assertStatus(422);
    $this->postJson("/office-api/crm/leads/{$other}/action", ['action' => 'lose', 'reason' => 'Too far'])->assertOk();
    expect($this->getJson('/office-api/crm/leads?open=0')->json('total'))->toBe(2);
});

it('works a deal through the pipeline: move, won, reopen, lost; and its quotation', function () {
    $c = Customer::create(['code' => 'C1', 'name' => 'Mensah Clinic', 'tenant_id' => $this->tenant->id, 'phone' => '+233244551234']);
    $this->actingAs($this->owner, 'web');
    $form = $this->getJson("/office-api/crm/deals/form?customer={$c->id}")->assertOk()->json();
    expect($form['values']['customer_name'])->toBe('Mensah Clinic')->and($form['stages'])->not->toBeEmpty();
    expect(collect($this->getJson('/office-api/crm/contacts?kind=customer&q=mens')->json('results'))->pluck('id'))->toContain($c->id);
    $id = $this->postJson('/office-api/crm/deals', ['title' => 'Monthly supply', 'stage_id' => $form['values']['stage_id'], 'customer_id' => $c->id, 'value' => '1200',
        'branch_id' => $form['values']['branch_id']])->assertOk()->json('id');
    expect(CrmDeal::find($id)->value_minor)->toBe(120000);

    $pipe = $this->getJson('/office-api/crm/pipeline')->assertOk()->json();
    expect($pipe['open'])->toBe(120000)->and($pipe['columns'][0]['deals'][0]['id'])->toBe($id);
    $next = $pipe['columns'][1]['id'];
    $this->postJson("/office-api/crm/deals/{$id}/action", ['action' => 'move', 'stage_id' => $next])->assertOk();
    expect(CrmDeal::find($id)->stage_id)->toBe($next);
    expect($this->getJson('/office-api/crm/pipeline?mine=1')->json('columns.1.deals.0.id'))->toBe($id);

    $won = $this->postJson("/office-api/crm/deals/{$id}/action", ['action' => 'win'])->assertOk()->json('deal');
    expect($won['status'])->toBe('won')->and($won['actions'])->toMatchArray(['reopen' => true, 'win' => false]);
    $this->postJson("/office-api/crm/deals/{$id}/action", ['action' => 'reopen'])->assertOk();
    $q = $this->postJson("/office-api/crm/deals/{$id}/action", ['action' => 'quote'])->assertOk()->json('open');
    expect($q)->toStartWith('/office/customer-orders/')->and($this->getJson("/office-api/crm/deals/{$id}")->json('quote.number'))->not->toBeNull();
    $this->postJson("/office-api/crm/deals/{$id}/action", ['action' => 'lose', 'reason' => 'Price'])->assertOk();
    expect(CrmDeal::find($id))->status->toBe('lost')->lost_reason->toBe('Price')
        ->and($this->getJson('/office-api/crm/deals?status=lost')->json('rows.0.lost_reason'))->toBe('Price');
});

it('keeps campaigns (reach, send, cancel, copy) and pipeline stages, and only for those allowed', function () {
    Customer::create(['code' => 'C1', 'name' => 'Abena', 'tenant_id' => $this->tenant->id, 'phone' => '+233244000001', 'consents' => ['sms' => ['granted' => true]]]);
    $this->actingAs($this->owner, 'web');
    expect($this->postJson('/office-api/crm/campaigns/reach', ['channel' => 'sms', 'audience' => []])->assertOk()->json('reach'))->toContain('Reaches');
    $this->postJson('/office-api/crm/campaigns', ['name' => 'Offers', 'channel' => 'email', 'body' => 'Hi {customer}'])->assertStatus(422); // an e-mail needs a subject
    $id = $this->postJson('/office-api/crm/campaigns', ['name' => 'December offers', 'channel' => 'sms', 'body' => 'Hi {customer}, 10% off this week.',
        'audience' => ['tags' => [], 'include_leads' => false]])->assertOk()->json('id');
    $list = $this->getJson('/office-api/crm/campaigns')->assertOk()->json('rows');
    expect($list[0])->toMatchArray(['name' => 'December offers', 'status' => 'draft', 'can_send' => true]);
    $copy = $this->postJson("/office-api/crm/campaigns/{$id}/action", ['action' => 'copy'])->assertOk()->json('open');
    expect(CrmCampaign::count())->toBe(2)->and($copy)->toStartWith('/office/crm/campaigns/');
    $this->postJson("/office-api/crm/campaigns/{$id}/action", ['action' => 'cancel'])->assertOk();
    expect(CrmCampaign::find($id)->status)->toBe('cancelled');

    $stages = $this->getJson('/office-api/crm/stages')->assertOk()->json('rows');
    expect(collect($stages)->pluck('name')->all())->toBe(['New', 'Contacted', 'Quotation sent', 'Negotiation', 'Won', 'Lost']);
    $this->postJson('/office-api/crm/stages', ['name' => 'Paid', 'kind' => 'won'])->assertStatus(422); // already a won stage
    $sid = $this->postJson('/office-api/crm/stages', ['name' => 'Demo given', 'kind' => 'open', 'probability' => 40, 'sort' => 25])->assertOk()->json('id');
    $this->postJson("/office-api/crm/stages/{$sid}/archive", ['reason' => 'Not used'])->assertOk();
    expect(CrmStage::find($sid)->archived_at)->not->toBeNull();

    // Customer service sees CRM but not the stages; a cashier sees none of it.
    $this->actingAs($this->agent, 'web');
    $this->getJson('/office-api/crm/stages')->assertForbidden();
    $cashier = withMfa(staff($this->owner, 'yaw', 3, 'cashier', ScopeType::Branch, $this->b['HQ']->id));
    $this->actingAs($cashier, 'web');
    foreach (['crm/leads', 'crm/deals', 'crm/pipeline', 'crm/follow-ups'] as $path) {
        $this->getJson("/office-api/{$path}")->assertForbidden();
    }
});
