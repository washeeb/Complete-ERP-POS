<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pharmacy\InteractionStarter;
use App\Domain\Pharmacy\RefillReminders;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\DrugInteraction;
use App\Models\Prescription;
use App\Models\PriceList;
use App\Models\RefillReminder;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Str;

// Pharmacy in the new back office (part 11): prescriptions, refill reminders, drug interactions, controlled-drug register.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $o = actorFor($this->owner);
    enableModule($this->owner, $this->hq, 'pharmacy');
    withMfa($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->amox = makeProduct($this->owner, 'AMX', 'Amoxicillin 500mg caps', retail: '2.00');
    app(CatalogService::class)->saveProduct($o, $this->amox, ['track_batches' => true, 'drug_schedule' => 'pom', 'generic_name' => 'amoxicillin', 'active_ingredients' => 'amoxicillin']);
    $this->amox = $this->amox->fresh();
    $this->amox->forceFill(['daily_use' => '3'])->save();
    app(InventoryService::class)->adjust($o, $this->wh, $this->amox, $this->amox->units->first(), '100', 'opening', '', null, '1.00', 'AMX-1', now()->addYear()->toDateString());
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($o, ['branch_id' => $this->hq->id, 'till_id' => $till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $till->id);
    $this->pharmacist = staff($this->owner, 'kwame', 4, 'pharmacist', ScopeType::Branch, $this->hq->id);
    app(AccessService::class)->setPin($o, $this->pharmacist, '771204');
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->patient = app(CustomerService::class)->save($o, new Customer, ['name' => 'Test Patient', 'phone' => '0241112222']);
    app(Settings::class)->set($o, 'refill.reminders', true);
});

/** One prescription sale of amoxicillin to the test patient, approved by the pharmacist. */
function officeRxSale($t, string $qty): void
{
    $p = $t->amox;
    $unit = $p->units->first();
    $price = app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
    $calc = PromotionEngine::compute(['lines' => [['key' => '0', 'product_id' => $p->id, 'category_id' => $p->category_id, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
        'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null]],
        'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    app(SaleService::class)->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => 1, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'customer_id' => $t->patient->id,
        'lines' => [['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price, 'line_total_minor' => $calc['lines'][0]['total']]],
        'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]],
        'prescription' => ['prescriber_name' => 'Dr. Test Prescriber', 'prescriber_reg_no' => 'MDC/0001', 'facility' => 'Test Clinic', 'issued_on' => now()->toDateString()],
        'pharmacist_approval' => ['username' => 'kwame', 'pin' => '771204']]);
}

it('lists and opens prescriptions, and lets staff cancel a refill reminder', function () {
    officeRxSale($this, '21');
    app(RefillReminders::class)->run();
    $this->actingAs($this->owner, 'web');

    $rows = $this->getJson('/office-api/prescriptions?q=prescriber')->assertOk()->json('rows');
    expect($rows)->toHaveCount(1)->and($rows[0])->toMatchArray(['patient' => 'TEST PATIENT', 'prescriber' => 'Dr. Test Prescriber', 'offline' => false]);
    $rx = $this->getJson('/office-api/prescriptions/'.Prescription::sole()->id)->assertOk()->json();
    expect($rx['medicines'])->toBe(['21 '.$this->amox->units->first()->uom->code.' × AMOXICILLIN 500MG CAPS'])->and($rx['facility'])->toBe('Test Clinic')
        ->and($rx['sale_id'])->not->toBeNull();
    expect($this->getJson('/office-api/prescriptions?offline=yes')->json('total'))->toBe(0);

    $r = RefillReminder::sole();
    $list = $this->getJson('/office-api/refill-reminders')->assertOk()->json();
    expect($list['rows'][0])->toMatchArray(['id' => $r->id, 'status' => 'waiting', 'customer' => 'TEST PATIENT', 'can_act' => true]);
    $this->postJson("/office-api/refill-reminders/{$r->id}/send")->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_contains($d, 'Not sent'));
    $this->postJson("/office-api/refill-reminders/{$r->id}/cancel")->assertOk()->assertJsonPath('message', 'Reminder cancelled.');
    expect($r->fresh()->status)->toBe('cancelled')->and(AuditEvent::where('action', 'refill_reminder.cancelled')->count())->toBe(1)
        ->and($this->getJson('/office-api/refill-reminders')->json('total'))->toBe(0)
        ->and($this->getJson('/office-api/refill-reminders?status=all')->json('total'))->toBe(1);

    $this->flushSession();
    $this->actingAs(withMfa($this->cashier), 'web');
    $this->getJson('/office-api/prescriptions')->assertForbidden();
    $this->getJson('/office-api/controlled-drug-register')->assertForbidden();
});

it('keeps the interaction table and shows the controlled-drug register', function () {
    $this->actingAs($this->owner, 'web');
    $id = $this->postJson('/office-api/drug-interactions', ['ingredient_a' => 'Warfarin', 'ingredient_b' => 'Aspirin', 'severity' => 'major', 'note' => 'Do not give together.'])
        ->assertOk()->json('id');
    expect(DrugInteraction::find($id)->only('ingredient_a', 'ingredient_b'))->toBe(['ingredient_a' => 'aspirin', 'ingredient_b' => 'warfarin']); // lower case, in order
    $this->postJson("/office-api/drug-interactions/{$id}", ['ingredient_a' => 'warfarin', 'ingredient_b' => 'aspirin', 'severity' => 'moderate', 'note' => 'Ask the prescriber.'])->assertOk();
    expect(DrugInteraction::find($id)->severity)->toBe('moderate');
    $this->postJson('/office-api/drug-interactions', ['ingredient_a' => 'x'])->assertStatus(422);
    $added = $this->postJson('/office-api/drug-interactions/starter')->assertOk()->json('message');
    expect($added)->toContain('interaction(s) added');
    $list = $this->getJson('/office-api/drug-interactions?q=warfarin')->assertOk()->json();
    expect($list['can_manage'])->toBeTrue()->and($list['starter'])->toBe(count(InteractionStarter::ROWS))->and($list['total'])->toBeGreaterThan(1);

    $mor = makeProduct($this->owner, 'MOR', 'Morphine 10mg tabs', retail: '40.00');
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $mor, ['track_batches' => true, 'drug_schedule' => 'controlled', 'strength' => '10 mg', 'active_ingredients' => 'morphine']);
    $mor = $mor->fresh();
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $mor, $mor->units->first(), '20', 'opening', '', null, '10.00', 'M-1', now()->addYear()->toDateString());
    $reg = $this->getJson('/office-api/controlled-drug-register?warehouse='.$this->wh->id)->assertOk()->json();
    // Only the stores of branches with pharmacy on (here HQ): never Branch A's grocery store.
    expect(collect($reg['warehouses'])->pluck('id')->all())->toBe(Warehouse::where('branch_id', $this->hq->id)->orderBy('code')->pluck('id')->all())
        ->and(Warehouse::where('branch_id', $this->branches['BA']->id)->exists())->toBeTrue();
    expect($reg['warehouse'])->toBe($this->wh->id)->and($reg['products'])->toHaveCount(1)->and($reg['products'][0]['closing'])->toBe('20')
        ->and($reg['products'][0]['rows'][0]['batch'])->toBe('M-1')->and($reg['print'])->toContain('/print/controlled-register')->and($reg['can_claims'])->toBeTrue();
    expect($this->getJson('/office-api/controlled-drug-register?from=2026-12-31&to=2026-01-01')->json('bad_period'))->toBeTrue();

    $this->flushSession();
    $nurse = staff($this->owner, 'abena', 2, 'nurse', ScopeType::Branch, $this->hq->id); // sees customers, not the pharmacy tables
    $this->actingAs(withMfa($nurse), 'web');
    $this->getJson('/office-api/drug-interactions')->assertForbidden();
    $this->postJson('/office-api/drug-interactions/starter')->assertForbidden();
    $this->getJson('/office-api/refill-reminders')->assertOk();
});
