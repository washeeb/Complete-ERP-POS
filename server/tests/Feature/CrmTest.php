<?php

use App\Domain\Crm\Crm;
use App\Domain\Customers\CustomerService;
use App\Domain\Sales\CustomerOrderService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\CrmFollowUps;
use App\Filament\Pages\CrmPipeline;
use App\Filament\Resources\CrmCampaigns\CrmCampaignResource;
use App\Filament\Resources\CrmDeals\CrmDealResource;
use App\Filament\Resources\CrmLeads\CrmLeadResource;
use App\Filament\Resources\CrmStages\CrmStageResource;
use App\Filament\Widgets\Panels\CrmPanel;
use App\Models\CrmActivity;
use App\Models\CrmCampaign;
use App\Models\CrmCampaignRecipient;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmStage;
use App\Models\Customer;
use App\Models\Message;
use App\Security\ScopeType;
use App\Support\Dashboard\Panels;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    activateTestLicense(['features' => ['finance', 'hr', 'crm']]);
    $this->crm = app(Crm::class);
    // Customer service at the head office only: works leads and deals there.
    $this->agent = staff($this->owner, 'efua', 2, 'customer_service', ScopeType::Branch, $this->branches['HQ']->id);
});

function lead($t, array $d = []): CrmLead
{
    return app(Crm::class)->saveLead(actorFor($t->agent), new CrmLead, $d + ['name' => 'Kofi Mensah', 'company_name' => 'Mensah Clinic', 'phone' => '024 455 1234',
        'source' => 'whatsapp', 'interest' => 'Monthly supply of gloves', 'consents' => ['sms' => true]]);
}

it('is a paid add-on: nothing without it on the licence', function () {
    activateTestLicense(['features' => ['finance', 'hr']]);

    expect(fn () => lead($this))->toThrow(DomainException::class, 'paid add-on');
    $this->actingAs(withMfa($this->owner), 'web')->get(CrmPipeline::getUrl())->assertForbidden();
});

it('starts every company with a pipeline and keeps one won and one lost stage', function () {
    expect(CrmStage::orderBy('sort')->pluck('name')->all())->toBe(['New', 'Contacted', 'Quotation sent', 'Negotiation', 'Won', 'Lost']);

    expect(fn () => $this->crm->saveStage(actorFor($this->owner), new CrmStage, ['name' => 'Paid', 'kind' => 'won']))->toThrow(DomainException::class, 'already a won stage')
        ->and(fn () => $this->crm->saveStage(actorFor($this->agent), new CrmStage, ['name' => 'Demo', 'kind' => 'open']))->toThrow(AccessDenied::class);
    $demo = $this->crm->saveStage(actorFor($this->owner), new CrmStage, ['name' => 'Demo given', 'kind' => 'open', 'probability' => 40, 'sort' => 25]);
    expect($this->crm->openStages()->pluck('name')->all())->toBe(['New', 'Contacted', 'Demo given', 'Quotation sent', 'Negotiation']);

    $this->crm->saveDeal(actorFor($this->agent), new CrmDeal, ['title' => 'Gloves', 'lead_id' => lead($this)->id, 'stage_id' => $demo->id]);
    expect(fn () => $this->crm->saveStage(actorFor($this->owner), $demo, ['name' => 'Demo given', 'kind' => 'open', 'is_active' => false]))->toThrow(DomainException::class, 'Move the deals');
});

it('works a lead to a customer, keeping consents and follow-ups, without duplicating a known customer', function () {
    $lead = lead($this);
    expect($lead)->number->toMatch('/^LD-/')->phone->toBe('+233244551234')->branch_id->toBe($this->branches['HQ']->id)->owner_id->toBe($this->agent->id);

    // A call made: the lead is now "contacted"; a follow-up planned for tomorrow.
    $this->crm->logActivity(actorFor($this->agent), ['lead_id' => $lead->id, 'type' => 'call', 'subject' => 'Called about prices', 'outcome' => 'Wants a quotation']);
    $this->crm->logActivity(actorFor($this->agent), ['lead_id' => $lead->id, 'type' => 'call', 'subject' => 'Call back', 'due_at' => now()->addDay()]);
    expect($lead->fresh()->status)->toBe('contacted');

    $r = $this->crm->convertLead(actorFor($this->agent), $lead->fresh(), 'Monthly gloves');
    expect($r['customer'])->name->toBe('MENSAH CLINIC')->contact_name->toBe('KOFI MENSAH')->and($r['customer']->hasConsent('sms'))->toBeTrue()->and($r['customer']->hasConsent('email'))->toBeFalse()
        ->and($r['deal'])->title->toBe('Monthly gloves')->customer_id->toBe($r['customer']->id)
        ->and($lead->fresh())->status->toBe('converted')->customer_id->toBe($r['customer']->id)
        ->and(CrmActivity::where('lead_id', $lead->id)->pluck('customer_id')->unique()->all())->toBe([$r['customer']->id]);

    // Someone already a customer (same phone) is linked, not created twice.
    $again = lead($this, ['name' => 'Kofi M.', 'company_name' => null]);
    expect($this->crm->convertLead(actorFor($this->agent), $again)['customer']->id)->toBe($r['customer']->id)
        ->and(Customer::where('phone', '+233244551234')->count())->toBe(1);

    $lost = lead($this, ['name' => 'Ama', 'phone' => '0201112222', 'company_name' => null]);
    expect(fn () => $this->crm->loseLead(actorFor($this->agent), $lost, ' '))->toThrow(DomainException::class, 'Say why');
    expect($this->crm->loseLead(actorFor($this->agent), $lost, 'Too far')->status)->toBe('lost')
        ->and(fn () => $this->crm->saveLead(actorFor($this->agent), $lost->fresh(), ['name' => 'Ama']))->toThrow(DomainException::class, 'closed');
});

it('keeps branch people to their branch', function () {
    expect(fn () => lead($this, ['branch_id' => $this->branches['BA']->id]))->toThrow(AccessDenied::class);
    $mine = lead($this);
    // Given to someone who cannot work this branch's records: refused.
    $baOnly = staff($this->owner, 'kwesi', 2, 'customer_service', ScopeType::Branch, $this->branches['BA']->id);
    expect(fn () => $this->crm->saveLead(actorFor($this->agent), $mine, ['owner_id' => $baOnly->id]))->toThrow(DomainException::class, 'Give it to someone');

    $other = $this->crm->saveLead(actorFor($this->owner), new CrmLead, ['name' => 'Yaw', 'phone' => '0277000000', 'branch_id' => $this->branches['BA']->id]);
    $this->actingAs(withMfa($this->agent), 'web')->get(CrmLeadResource::getUrl())->assertOk()->assertSee($mine->number)->assertDontSee($other->number);
});

it('runs a deal from lead to quotation to order: confirming the order wins the deal', function () {
    $deal = $this->crm->saveDeal(actorFor($this->agent), new CrmDeal, ['title' => 'Gloves for the year', 'lead_id' => lead($this)->id, 'value_minor' => 500000,
        'expected_close_on' => now()->addMonth()->toDateString()]);
    expect($deal)->number->toMatch('/^DL-/')->status->toBe('open')->stage->name->toBe('New');

    $contacted = CrmStage::where('name', 'Contacted')->first();
    $this->crm->moveDeal(actorFor($this->agent), $deal, $contacted);
    expect($deal->fresh()->stage_id)->toBe($contacted->id);

    // The quotation needs a customer: the lead becomes one.
    $order = $this->crm->quoteForDeal(actorFor($this->owner), $deal->fresh());
    $deal->refresh();
    expect($order)->status->toBe('quotation')->customer_ref->toBe($deal->number)
        ->and($deal)->customer_order_id->toBe($order->id)->customer_id->not->toBeNull()->stage->name->toBe('Quotation sent')
        ->and($deal->lead->status)->toBe('converted');

    $product = makeProduct($this->owner, 'GLV', 'Gloves (box)', retail: '45.00');
    $orders = app(CustomerOrderService::class);
    $orders->saveQuotation(actorFor($this->owner), $order, ['lines' => [['product_uom_id' => $product->units->first()->id, 'qty' => '10']]]);
    $orders->confirm(actorFor($this->owner), $order->fresh());

    expect($deal->fresh())->status->toBe('won')->value_minor->toBe(45000)->stage->kind->toBe('won')->closed_at->not->toBeNull();
});

it('loses a deal with a reason and can reopen it', function () {
    $deal = $this->crm->saveDeal(actorFor($this->agent), new CrmDeal, ['title' => 'Gloves', 'lead_id' => lead($this)->id]);
    $this->crm->logActivity(actorFor($this->agent), ['deal_id' => $deal->id, 'type' => 'meeting', 'subject' => 'Visit', 'due_at' => now()->addDays(2)]);

    expect(fn () => $this->crm->loseDeal(actorFor($this->agent), $deal, ''))->toThrow(DomainException::class, 'Say why');
    $this->crm->loseDeal(actorFor($this->agent), $deal, 'Went to a competitor');
    expect($deal->fresh())->status->toBe('lost')->lost_reason->toBe('Went to a competitor')
        ->and(CrmActivity::where('deal_id', $deal->id)->whereNull('done_at')->count())->toBe(0)
        ->and(fn () => $this->crm->saveDeal(actorFor($this->agent), $deal->fresh(), ['title' => 'x']))->toThrow(DomainException::class, 'closed');

    expect($this->crm->reopenDeal(actorFor($this->agent), $deal->fresh()))->status->toBe('open')->lost_reason->toBeNull();
});

it('reminds the person a follow-up is given to, once, when it is due', function () {
    $lead = lead($this);
    $this->crm->logActivity(actorFor($this->owner), ['lead_id' => $lead->id, 'type' => 'call', 'subject' => 'Call Kofi', 'due_at' => now()->addMinutes(5), 'assigned_to' => $this->agent->id]);
    $given = $this->agent->notifications()->count(); // told it was given to them

    expect($this->crm->remindDue($this->tenant->id))->toBe(1)
        ->and($this->crm->remindDue($this->tenant->id))->toBe(0)
        ->and($this->agent->notifications()->count())->toBe($given + 1)
        ->and($this->agent->notifications()->latest()->first()->data['title'])->toContain('Call Kofi');
});

it('sends a campaign only to people who agreed, with a way out of e-mails', function () {
    $svc = app(CustomerService::class);
    $vip = $svc->save(actorFor($this->owner), new Customer, ['name' => 'Abena', 'phone' => '0244000001', 'email' => 'abena@example.com', 'tags' => ['VIP'], 'consents' => ['sms' => true, 'email' => true]]);
    $svc->save(actorFor($this->owner), new Customer, ['name' => 'Kwame', 'phone' => '0244000002', 'tags' => ['VIP'], 'consents' => ['sms' => false]]);
    $svc->save(actorFor($this->owner), new Customer, ['name' => 'Esi', 'tags' => ['VIP'], 'consents' => ['sms' => true]]); // no phone
    $svc->save(actorFor($this->owner), new Customer, ['name' => 'Yaw', 'phone' => '0244000004', 'tags' => ['wholesale'], 'consents' => ['sms' => true]]);
    $marketing = staff($this->owner, 'adwoa', 3, 'marketing');

    expect(fn () => $this->crm->saveCampaign(actorFor($marketing), new CrmCampaign, ['name' => 'X', 'channel' => 'whatsapp', 'body' => 'Hi']))->toThrow(DomainException::class, 'template')
        ->and(fn () => $this->crm->saveCampaign(actorFor($this->agent), new CrmCampaign, ['name' => 'X', 'channel' => 'sms', 'body' => 'Hi']))->toThrow(AccessDenied::class);

    $sms = $this->crm->saveCampaign(actorFor($marketing), new CrmCampaign, ['name' => 'December', 'channel' => 'sms', 'body' => 'Hello {customer}, 10% off at {company} this week.',
        'audience' => ['tags' => ['VIP']]]);
    expect($this->crm->preview($sms))->toBe(['customers' => 3, 'leads' => 0, 'reachable' => 1]);

    $sent = $this->crm->send(actorFor($marketing), $sms);
    expect($sent)->status->toBe('sent')->sent->toBe(1)->skipped->toBe(2)
        ->and(Message::where('reference', $sms->number)->sole())->to->toBe('+233244000001')->body->toBe('Hello ABENA, 10% off at Demo Ltd this week.')->status->toBe('queued')
        ->and(CrmCampaignRecipient::where('status', 'skipped')->pluck('skip_reason')->sort()->values()->all())->toBe(['did not agree to SMS', 'no usable phone number'])
        ->and(fn () => $this->crm->send(actorFor($marketing), $sms->fresh()))->toThrow(DomainException::class, 'already gone out');

    $mail = $this->crm->saveCampaign(actorFor($marketing), new CrmCampaign, ['name' => 'News', 'channel' => 'email', 'subject' => 'News for {customer}', 'body' => 'New stock.', 'audience' => ['tags' => ['VIP']]]);
    $this->crm->send(actorFor($marketing), $mail);
    $msg = Message::where('reference', $mail->number)->sole();
    expect($msg->subject)->toBe('News for ABENA')->and($msg->body)->toContain('To stop receiving these e-mails: ');

    // The link asks first (mail scanners open links); the button stops them.
    $link = URL::signedRoute('crm.unsubscribe', ['kind' => 'customer', 'id' => $vip->id, 'channel' => 'email']);
    $this->get($link)->assertOk()->assertSee('Yes, stop them');
    expect($vip->fresh()->hasConsent('email'))->toBeTrue();
    $this->post($link)->assertOk()->assertSee('no longer receive');
    expect($vip->fresh()->hasConsent('email'))->toBeFalse()->and($vip->fresh()->hasConsent('sms'))->toBeTrue();
    $this->post(str_replace('/email', '/sms', $link))->assertForbidden(); // the signature covers the channel
});

it('waits for a scheduled campaign time', function () {
    app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Abena', 'phone' => '0244000001', 'consents' => ['sms' => true]]);
    $c = $this->crm->saveCampaign(actorFor($this->owner), new CrmCampaign, ['name' => 'Sat', 'channel' => 'sms', 'body' => 'Open Saturday', 'send_at' => now()->addHour()]);

    expect($this->crm->send(actorFor($this->owner), $c)->status)->toBe('scheduled')
        ->and($this->crm->sendDue($this->tenant->id))->toBe(0);
    $this->travel(2)->hours();
    expect($this->crm->sendDue($this->tenant->id))->toBe(1)->and($c->fresh())->status->toBe('sent')->sent->toBe(1);
});

it('shows the CRM pages to people with the rights', function () {
    $deal = $this->crm->saveDeal(actorFor($this->agent), new CrmDeal, ['title' => 'Gloves for the year', 'lead_id' => lead($this)->id, 'value_minor' => 500000]);
    $this->crm->logActivity(actorFor($this->agent), ['deal_id' => $deal->id, 'type' => 'call', 'subject' => 'Ring about delivery', 'due_at' => now()->subHour()]);
    $owner = withMfa($this->owner);

    $this->actingAs($owner, 'web')->get(CrmPipeline::getUrl())->assertOk()->assertSee('Gloves for the year')->assertSee('Quotation sent');
    $this->actingAs($owner, 'web')->get(CrmDealResource::getUrl())->assertOk()->assertSee($deal->number);
    $this->actingAs($owner, 'web')->get(CrmDealResource::getUrl('edit', ['record' => $deal]))->assertOk()->assertSee('Make quotation');
    $this->actingAs($owner, 'web')->get(CrmLeadResource::getUrl('create'))->assertOk();
    $this->actingAs($owner, 'web')->get(CrmCampaignResource::getUrl('create'))->assertOk()->assertSee('Who gets it');
    $this->actingAs($owner, 'web')->get(CrmStageResource::getUrl())->assertOk()->assertSee('Negotiation');
    $this->actingAs($owner, 'web')->get('/admin')->assertOk();
    expect(array_column(Panels::layoutFor($owner), 'key'))->toContain('crm'); // the dashboard's panels load after the page
});

it('shows a person their own follow-ups', function () {
    $this->crm->logActivity(actorFor($this->agent), ['lead_id' => lead($this)->id, 'type' => 'call', 'subject' => 'Ring about delivery', 'due_at' => now()->subHour()]);

    $this->actingAs(withMfa($this->agent), 'web')->get(CrmFollowUps::getUrl())->assertOk()->assertSee('Ring about delivery')->assertSee('Overdue');
});

it('keeps CRM from people without the rights', function () {
    $keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);

    $this->actingAs(withMfa($keeper), 'web')->get(CrmPipeline::getUrl())->assertForbidden();
});

it('moves deals on the pipeline board and shows the dashboard panel', function () {
    $a = $this->crm->saveDeal(actorFor($this->agent), new CrmDeal, ['title' => 'Gloves', 'lead_id' => lead($this)->id, 'value_minor' => 100000]);
    $b = $this->crm->saveDeal(actorFor($this->agent), new CrmDeal, ['title' => 'Masks', 'lead_id' => $a->lead_id, 'value_minor' => 50000]);
    $this->actingAs(withMfa($this->owner), 'web');
    $negotiation = CrmStage::where('name', 'Negotiation')->first();

    Livewire::test(CrmPipeline::class)->call('move', $a->id, $negotiation->id)->call('move', $b->id, 'won')
        ->callAction('lose', ['reason' => 'Price'], ['deal' => $a->id])->assertHasNoActionErrors();
    expect($b->fresh()->status)->toBe('won')->and($a->fresh())->status->toBe('lost')->lost_reason->toBe('Price');

    Livewire::test(CrmPanel::class)->assertSee('Won this month')->assertSee('500.00');
});
