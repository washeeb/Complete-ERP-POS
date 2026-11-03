<?php

use App\Domain\Customers\CustomerService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Inventory\InventoryService;
use App\Domain\Payables\BillService;
use App\Domain\Purchasing\SupplierService;
use App\Domain\Service\Maintenance;
use App\Domain\Service\ServiceDesk;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ServiceLevels;
use App\Filament\Resources\MaintenanceAssets\MaintenanceAssetResource;
use App\Filament\Resources\MaintenanceOrders\MaintenanceOrderResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Filament\Widgets\Panels\ServicePanel;
use App\Models\Account;
use App\Models\Customer;
use App\Models\MaintenanceAsset;
use App\Models\MaintenanceOrder;
use App\Models\MaintenancePlan;
use App\Models\Message;
use App\Models\ServiceLevel;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    activateTestLicense(['features' => ['finance', 'hr', 'service']]);
    $this->hq = $this->branches['HQ'];
    $this->store = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->desk = app(ServiceDesk::class);
    $this->m = app(Maintenance::class);
    $this->agent = staff($this->owner, 'efua', 2, 'customer_service', ScopeType::Branch, $this->hq->id);
    $this->tech = staff($this->owner, 'kofi', 2, 'technician', ScopeType::Branch, $this->hq->id);
    $this->customer = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama Darko', 'phone' => '0244000111', 'email' => 'ama@example.com']);
});

function ticket($t, array $d = []): Ticket
{
    return app(ServiceDesk::class)->open(actorFor($t->agent), $d + ['subject' => 'Blender stopped working', 'description' => 'Bought last month, now it will not start.',
        'kind' => 'support', 'priority' => 'high', 'customer_id' => $t->customer->id, 'branch_id' => $t->hq->id]);
}

it('is a paid add-on', function () {
    activateTestLicense(['features' => ['finance', 'hr']]);

    expect(fn () => ticket($this))->toThrow(DomainException::class, 'paid add-on');
    $this->actingAs(withMfa($this->owner), 'web')->get(TicketResource::getUrl())->assertForbidden();
});

it('opens a ticket with deadlines by priority and lets staff ask for help for themselves only', function () {
    expect(ServiceLevel::where('priority', 'high')->sole())->respond_minutes->toBe(240)->resolve_minutes->toBe(1440);
    $this->freezeTime();
    $t = ticket($this);
    expect($t)->number->toMatch('/^TCK-/')->status->toBe('new')->contact_phone->toBe('+233244000111')
        ->and($t->respond_by->toDateTimeString())->toBe(now()->addHours(4)->toDateTimeString())->and($t->resolve_by->toDateTimeString())->toBe(now()->addDay()->toDateTimeString())
        ->and($t->messages()->sole()->direction)->toBe('in');

    $cashier = staff($this->owner, 'yaa', 3, 'cashier', ScopeType::Branch, $this->hq->id);
    $own = $this->desk->open(actorFor($cashier), ['kind' => 'internal', 'subject' => 'Receipt printer jams']);
    expect($own)->kind->toBe('internal')->source->toBe('staff')->raised_by->toBe($cashier->id)
        ->and(fn () => $this->desk->open(actorFor($cashier), ['kind' => 'complaint', 'subject' => 'x', 'customer_id' => $this->customer->id]))->toThrow(AccessDenied::class);
});

it('answers the customer, stops the clock while waiting, resolves and asks how it went', function () {
    $t = ticket($this);
    $this->desk->assign(actorFor($this->agent), $t, $this->agent->id);
    expect($t->fresh()->status)->toBe('open');

    $this->freezeTime();
    $this->desk->reply(actorFor($this->agent), $t->fresh(), 'Please send a photo of the plug.', 'sms', wait: true);
    $t->refresh();
    $resolveBy = $t->resolve_by;
    expect($t)->status->toBe('waiting')->first_response_at->not->toBeNull()
        ->and(Message::where('reference', $t->number)->sole())->channel->toBe('sms')->to->toBe('+233244000111');

    // Two hours later they answer by e-mail (quoting the ticket number): the deadline moves on by two hours.
    $this->travel(2)->hours();
    $this->desk->fromEmail($this->tenant, 'ama@example.com', 'Ama', "Re: Blender [{$t->number}]", 'Here is the photo.');
    $t->refresh();
    expect($t->status)->toBe('open')->and($t->resolve_by->equalTo($resolveBy->copy()->addHours(2)))->toBeTrue()->and($t->messages()->count())->toBe(3);

    $this->desk->resolve(actorFor($this->agent), $t, 'Replaced under warranty.', 'email');
    $t->refresh();
    $mail = Message::where('reference', $t->number)->where('channel', 'email')->latest()->first();
    expect($t)->status->toBe('resolved')->resolution->toBe('Replaced under warranty.')->and($mail->body)->toContain('How did we do?');

    $link = URL::signedRoute('ticket.rate', ['ticket' => $t->id]);
    $this->get($link)->assertOk()->assertSee('How did we do?');
    $this->post($link, ['rating' => 5])->assertOk()->assertSee('Thank you');
    expect($t->fresh()->rating)->toBe(5);

    // A week later it closes by itself.
    $this->travel(8)->days();
    expect($this->desk->watch($this->tenant->id)['closed'])->toBe(1)->and($t->fresh()->status)->toBe('closed');
});

it('warns before a deadline and tells managers when it is missed', function () {
    $this->freezeTime();
    $t = ticket($this, ['assigned_to' => $this->agent->id]); // answer within 4 hours
    $this->travel(2)->hours();
    expect($this->desk->watch($this->tenant->id))->toMatchArray(['warned' => 0, 'escalated' => 0]);
    $this->travel(70)->minutes(); // 3 h 10 of 4 h gone
    expect($this->desk->watch($this->tenant->id)['warned'])->toBe(1)->and($this->desk->watch($this->tenant->id)['warned'])->toBe(0);
    $this->travel(1)->hours();
    expect($this->desk->watch($this->tenant->id)['escalated'])->toBe(1)->and($t->fresh()->escalated_at)->not->toBeNull()
        ->and($this->owner->notifications()->where('data->title', 'like', "%{$t->number} is late%")->exists())->toBeTrue();
});

it('takes tickets from the web form and e-mail, and WhatsApp messages about an open ticket', function () {
    $this->get('/help/'.$this->tenant->slug)->assertNotFound(); // switched off
    app(Settings::class)->set(actorFor($this->owner), 'service.web_form', true);
    $this->get('/help/'.$this->tenant->slug)->assertOk()->assertSee('How can we help?');
    $this->post('/help/'.$this->tenant->slug, ['contact_name' => 'Kwesi', 'contact_phone' => '0201234567', 'subject' => 'Wrong change', 'description' => 'I was given 5 cedis short.', 'kind' => 'complaint'])
        ->assertOk()->assertSee('Thank you');
    $this->post('/help/'.$this->tenant->slug, ['contact_name' => 'Bot', 'contact_phone' => '0200000000', 'subject' => 'x', 'description' => 'x', 'website' => 'spam.example']);
    expect(Ticket::where('source', 'web')->sole())->kind->toBe('complaint')->contact_phone->toBe('+233201234567');

    $key = str_repeat('k3y', 9);
    app(Settings::class)->set(actorFor($this->owner), 'service.email_key', $key);
    $this->post(route('service.email', ['key' => 'wrongwrongwrongwrongwrong']), ['from' => 'x@example.com', 'subject' => 'x', 'text' => 'x'])->assertNotFound();
    $this->post(route('service.email', ['key' => $key]), ['from' => 'Kojo Mensah <kojo@example.com>', 'subject' => 'Invoice question', 'text' => 'Why is VAT on this?'])->assertNoContent();
    expect(Ticket::where('source', 'email')->sole())->contact_email->toBe('kojo@example.com')->contact_name->toBe('Kojo Mensah');

    $wa = ticket($this, ['source' => 'whatsapp']);
    expect($this->desk->absorbWhatsapp($this->tenant->id, '233244000111', 'Any news?'))->toBeTrue()
        ->and($wa->messages()->get()->last()->body)->toBe('Any news?')
        ->and($this->desk->absorbWhatsapp($this->tenant->id, '233209999999', 'I want to order'))->toBeFalse();
});

it('keeps the company\'s equipment serviced: plans open work orders, the job puts it back to work', function () {
    $gen = $this->m->saveAsset(actorFor($this->owner), new MaintenanceAsset, ['name' => 'Generator 20 kVA', 'category' => 'generator', 'branch_id' => $this->hq->id, 'meter_unit' => 'hours']);
    $this->m->meter(actorFor($this->tech), $gen, 1000);
    $plan = $this->m->savePlan(actorFor($this->owner), $gen, new MaintenancePlan, ['name' => 'Oil and filter change', 'every_days' => 90, 'every_meter' => 250,
        'last_done_on' => now()->subDays(80)->toDateString(), 'last_meter' => 1000, 'lead_days' => 7, 'assigned_to' => $this->tech->id, 'checklist' => ['Drain oil', 'New filter']]);
    expect($plan->next_due_on->toDateString())->toBe(now()->addDays(10)->toDateString())->and($this->m->duePlans($this->tenant->id))->toBe(0);

    // The meter passes 1,250 hours: due now; only one work order however often it is checked.
    $this->m->meter(actorFor($this->tech), $gen->fresh(), 1260);
    expect($this->m->duePlans($this->tenant->id))->toBe(1)->and($this->m->duePlans($this->tenant->id))->toBe(0);
    $o = MaintenanceOrder::sole();
    expect($o)->kind->toBe('planned')->assigned_to->toBe($this->tech->id)->checklist->toHaveCount(2);

    expect(fn () => $this->m->complete(actorFor($this->tech), $o, ['outcome' => 'Oil changed']))->toThrow(DomainException::class, 'checklist');
    $this->m->labour(actorFor($this->tech), $o, 90);
    $this->m->complete(actorFor($this->tech), $o->fresh(), ['outcome' => 'Oil and filter changed', 'meter_reading' => 1262,
        'checklist' => [['item' => 'Drain oil', 'done' => true], ['item' => 'New filter', 'done' => true]]]);
    expect($o->fresh())->status->toBe('done')->labour_minutes->toBe(90)
        ->and($plan->fresh())->last_meter->toEqual('1262.0')->and($plan->fresh()->next_due_on->toDateString())->toBe(now()->addDays(90)->toDateString())
        ->and($gen->fresh()->meter_reading)->toEqual('1262.0');
});

it('opens an urgent breakdown on a sensor alarm, once, and counts the downtime', function () {
    $fridge = $this->m->saveAsset(actorFor($this->owner), new MaintenanceAsset, ['name' => 'Vaccine fridge', 'category' => 'fridge', 'branch_id' => $this->hq->id]);

    $this->freezeTime();
    $o = $this->m->breakdown($this->tenant->id, $fridge->id, 'Temperature 11 °C (above 8)');
    $again = $this->m->breakdown($this->tenant->id, $fridge->id, 'Temperature 12 °C (above 8)');
    expect($again->id)->toBe($o->id)->and($o->fresh())->priority->toBe('urgent')->source->toBe('sensor')
        ->and($o->fresh()->details)->toContain('12 °C')->and($fridge->fresh()->status)->toBe('down');

    $this->travel(3)->hours();
    $this->m->complete(actorFor($this->tech), $o->fresh(), ['outcome' => 'Thermostat replaced']);
    expect($fridge->fresh()->status)->toBe('working')->and($o->fresh()->downtimeMinutes())->toBe(180);
});

it('charges parts and outside technicians to the job, and to the right place in the books', function () {
    $part = makeProduct($this->owner, 'FLT', 'Oil filter', retail: '80.00', tax: 'EXEMPT');
    $unit = $part->units->firstWhere('is_base', true);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->store, $part, $unit, '10', 'opening', '', unitCost: '50.00');
    $gen = $this->m->saveAsset(actorFor($this->owner), new MaintenanceAsset, ['name' => 'Generator', 'branch_id' => $this->hq->id]);
    $mower = $this->m->saveAsset(actorFor($this->owner), new MaintenanceAsset, ['name' => 'Customer mower', 'owner' => 'customer', 'customer_id' => $this->customer->id,
        'branch_id' => $this->hq->id, 'warranty_until' => now()->subMonth()->toDateString()]);

    $own = $this->m->open(actorFor($this->tech), $gen, ['kind' => 'breakdown', 'title' => 'Not starting']);
    $this->m->parts(actorFor($this->owner), $own, $this->store, [['product_uom_id' => $unit->id, 'qty' => '2']]);
    $supplier = app(SupplierService::class)->save(actorFor($this->owner), new Supplier, ['name' => 'Gen Masters']);
    app(BillService::class)->create(actorFor($this->owner), $supplier, $this->hq, ['supplier_invoice_no' => 'GM-1', 'bill_date' => now()->toDateString(),
        'lines' => [['account_id' => Account::where('system_key', 'repairs')->value('id'), 'description' => 'Rewinding', 'amount_minor' => 30000, 'maintenance_order_id' => $own->id]]]);
    expect($own->fresh())->parts_minor->toBe(10000)->outside_minor->toBe(30000);

    $job = $this->m->open(actorFor($this->tech), $mower, ['kind' => 'visit', 'title' => 'Blade worn']);
    expect($job->chargeable)->toBeTrue(); // out of warranty, no contract
    $this->m->parts(actorFor($this->owner), $job, $this->store, [['product_uom_id' => $unit->id, 'qty' => '1']]);
    expect(fn () => $this->m->invoice(actorFor($this->owner), $job->fresh(), null, 0))->toThrow(DomainException::class, 'Finish the job');
    $this->m->complete(actorFor($this->tech), $job->fresh(), ['outcome' => 'Filter and blade done']);
    $service = makeProduct($this->owner, 'LAB', 'Repair labour', retail: '0.00', tax: 'EXEMPT');
    $service->forceFill(['track_stock' => false])->save();
    $invoice = $this->m->invoice(actorFor($this->owner), $job->fresh(), $service, 5000);
    expect($invoice->net_minor)->toBe(8000 + 5000)->and($job->fresh()->invoice_id)->toBe($invoice->id);

    app(AutoPoster::class)->run($this->tenant->id);
    $bal = fn (string $key) => (int) DB::table('journal_lines')->where('account_id', Account::where('system_key', $key)->value('id'))->sum('amount_minor');
    expect($bal('repairs'))->toBe(10000 + 30000)->and($bal('cogs'))->toBe(5000)->and($bal('warranty_costs'))->toBe(0)
        ->and(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('checks warranty from the equipment, and refuses an unknown receipt', function () {
    $tv = $this->m->saveAsset(actorFor($this->owner), new MaintenanceAsset, ['name' => 'TV 55"', 'owner' => 'customer', 'customer_id' => $this->customer->id,
        'warranty_until' => now()->addMonths(6)->toDateString()]);

    expect(ticket($this, ['kind' => 'warranty', 'asset_id' => $tv->id])->under_warranty)->toBeTrue()
        ->and(fn () => ticket($this, ['kind' => 'return', 'receipt_no' => 'NO-SUCH']))->toThrow(DomainException::class, 'No sale with receipt number');
});

it('shows the service desk and maintenance pages to the people with the rights', function () {
    $t = ticket($this);
    $this->desk->note(actorFor($this->agent), $t, 'Called them back.');
    $gen = $this->m->saveAsset(actorFor($this->owner), new MaintenanceAsset, ['name' => 'Generator', 'branch_id' => $this->hq->id]);
    $o = $this->m->open(actorFor($this->tech), $gen, ['title' => 'Noisy']);
    $owner = withMfa($this->owner);

    $this->actingAs($owner, 'web')->get(TicketResource::getUrl())->assertOk()->assertSee($t->number);
    $this->actingAs($owner, 'web')->get(TicketResource::getUrl('view', ['record' => $t]))->assertOk()->assertSee('Called them back.')->assertSee('Reply');
    $this->actingAs($owner, 'web')->get(TicketResource::getUrl('create'))->assertOk();
    $this->actingAs($owner, 'web')->get(ServiceLevels::getUrl())->assertOk()->assertSee('Urgent');
    $this->actingAs($owner, 'web')->get(MaintenanceAssetResource::getUrl('edit', ['record' => $gen]))->assertOk()->assertSee('Report a breakdown');
    $this->actingAs($owner, 'web')->get(MaintenanceOrderResource::getUrl('view', ['record' => $o]))->assertOk()->assertSee('Log time');
    Livewire::actingAs($owner)->test(ServicePanel::class)->assertSee('Open tickets');
});

it('keeps the service desk from people without the rights', function () {
    $keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->hq->id);

    $this->actingAs(withMfa($keeper), 'web')->get(MaintenanceOrderResource::getUrl())->assertForbidden();
});
