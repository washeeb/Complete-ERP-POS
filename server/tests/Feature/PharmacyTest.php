<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Devices\OfflineGrant;
use App\Domain\Finance\AutoPoster;
use App\Domain\Grocery\MarkdownService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Modules\ModuleService;
use App\Domain\Org\OrgService;
use App\Domain\Pharmacy\InteractionStarter;
use App\Domain\Pharmacy\NhisClaims;
use App\Domain\Pharmacy\PharmacyReports;
use App\Domain\Pharmacy\PharmacyRules;
use App\Domain\Pharmacy\PharmacyService;
use App\Domain\Pharmacy\PharmacySignoffs;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ControlledDrugRegister;
use App\Filament\Pages\MedicineSignoffs;
use App\Filament\Pages\PharmacyMarkdowns;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\DrugInteractions\DrugInteractionResource;
use App\Filament\Resources\NhisClaims\NhisClaimResource;
use App\Filament\Resources\NhisClaims\Pages\ViewNhisClaim;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Account;
use App\Models\Customer;
use App\Models\DrugInteraction;
use App\Models\Prescription;
use App\Models\PriceList;
use App\Models\ReviewItem;
use App\Models\Sale;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    enableModule($this->owner, $this->hq, 'pharmacy');
    $catalog = app(CatalogService::class);
    $inv = app(InventoryService::class);
    $drug = function (string $sku, string $name, string $price, array $fields) use ($catalog, $inv) {
        $p = makeProduct($this->owner, $sku, $name, retail: $price);
        $catalog->saveProduct(actorFor($this->owner), $p, ['track_batches' => true] + $fields);
        $p = $p->fresh();
        $inv->adjust(actorFor($this->owner), $this->wh, $p, $p->units->first(), '50', 'opening', '', null, '1.00', "{$sku}-1", now()->addYear()->toDateString());

        return $p->fresh();
    };
    $this->amox = $drug('AMX', 'Amoxicillin 500mg caps', '25.00', ['drug_schedule' => 'pom', 'generic_name' => 'Amoxicillin', 'active_ingredients' => 'Amoxicillin']);
    $this->warf = $drug('WRF', 'Warfarin 5mg tabs', '30.00', ['drug_schedule' => 'pom', 'generic_name' => 'warfarin', 'active_ingredients' => ['Warfarin']]);
    $this->para = $drug('PCM', 'Paracetamol 500mg tabs', '5.00', ['drug_schedule' => 'otc', 'generic_name' => 'paracetamol', 'active_ingredients' => 'paracetamol']);
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->pharmacist = staff($this->owner, 'kwame', 4, 'pharmacist', ScopeType::Branch, $this->hq->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->pharmacist, '771204');
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
    $this->ama = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222']);
    $this->rx = ['prescriber_name' => 'Dr. K. Mensah', 'prescriber_reg_no' => 'MDC/1234', 'facility' => 'Ridge Hospital', 'issued_on' => now()->toDateString()];
});

function rxSale($t, array $items, array $extra = [], bool $offline = false, $cashier = null): Sale
{
    $lines = [];
    $engine = [];
    foreach ($items as $i => [$p, $qty]) {
        $unit = $p->units->first();
        $price = app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
        $engine[] = ['key' => (string) $i, 'product_id' => $p->id, 'category_id' => $p->category_id, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
            'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price];
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }
    $payload = ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $t->retail->id,
        'shift_id' => $t->shift->id, 'lines' => $lines, 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]] + $extra;
    $cashier ??= $t->cashier;
    if ($offline) {
        $grant = app(OfflineGrant::class)->issue($cashier, $t->device, '482613');

        return app(SaleService::class)->post($t->device, null, $payload, app(OfflineGrant::class)->verify($grant))['sale'];
    }

    return app(SaleService::class)->post($t->device, $cashier, $payload)['sale'];
}

it('keeps medicines batch-tracked and their ingredients normalised', function () {
    expect($this->amox->active_ingredients)->toBe(['amoxicillin'])->and($this->amox->needsPrescription())->toBeTrue()
        ->and($this->para->needsPrescription())->toBeFalse();
    $p = makeProduct($this->owner, 'IBU', 'Ibuprofen', retail: '6.00');
    expect(fn () => app(CatalogService::class)->saveProduct(actorFor($this->owner), $p, ['drug_schedule' => 'otc']))->toThrow(DomainException::class)
        ->and(fn () => app(CatalogService::class)->saveProduct(actorFor($this->owner), $p, ['drug_schedule' => 'herbal', 'track_batches' => true]))->toThrow(DomainException::class);
});

it('needs the patient, the prescription and a pharmacist before selling a prescription-only medicine', function () {
    app(Settings::class)->set(actorFor($this->owner), 'pos.shift_per_person', false); // the pharmacist also sells on the cashier's till here
    $items = [[$this->amox, '1'], [$this->para, '2']];
    expect(fn () => rxSale($this, $items))->toThrow(DomainException::class, 'choose the patient')
        ->and(fn () => rxSale($this, $items, ['customer_id' => $this->ama->id]))->toThrow(DomainException::class, 'prescriber')
        ->and(fn () => rxSale($this, $items, ['customer_id' => $this->ama->id, 'prescription' => $this->rx]))->toThrow(DomainException::class, 'pharmacist must approve')
        ->and(fn () => rxSale($this, $items, ['customer_id' => $this->ama->id, 'prescription' => $this->rx, 'pharmacist_approval' => ['username' => 'kwame', 'pin' => '000000']]))
        ->toThrow(DomainException::class, 'wrong');
    $supervisor = staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->hq->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $supervisor, '551234');
    expect(fn () => rxSale($this, $items, ['customer_id' => $this->ama->id, 'prescription' => $this->rx, 'pharmacist_approval' => ['username' => 'kojo', 'pin' => '551234']]))
        ->toThrow(DomainException::class, 'cannot approve');
    expect(Sale::count())->toBe(0);

    $sale = rxSale($this, $items, ['customer_id' => $this->ama->id, 'prescription' => $this->rx, 'pharmacist_approval' => ['username' => 'kwame', 'pin' => '771204']]);
    $rx = Prescription::sole();
    expect($rx->number)->toStartWith('RX-')->and($rx->sale_id)->toBe($sale->id)->and($rx->pharmacist_name)->toBe('Kwame')
        ->and($rx->prescriber_reg_no)->toBe('MDC/1234')->and($rx->approved_offline)->toBeFalse()
        ->and($sale->lines->firstWhere('product_id', $this->amox->id)->prescription_id)->toBe($rx->id)
        ->and($sale->lines->firstWhere('product_id', $this->para->id)->prescription_id)->toBeNull()
        ->and(DB::table('outbox_events')->where('event_type', 'prescription.dispensed')->count())->toBe(1);

    // A pharmacist on the till dispenses without a second approval; an OTC-only sale needs nothing.
    $this->shift = app(ShiftService::class)->current($this->device);
    rxSale($this, [[$this->amox, '1']], ['customer_id' => $this->ama->id, 'prescription' => $this->rx], cashier: $this->pharmacist);
    rxSale($this, [[$this->para, '1']]);
    expect(Prescription::count())->toBe(2);
});

it('warns about allergies and interactions, and records what the pharmacist saw', function () {
    $svc = app(PharmacyService::class);
    $svc->savePatient(actorFor($this->owner), $this->ama, ['allergies' => 'Amoxicillin, sulfa', 'nhis_no' => 'gh-123']);
    $svc->saveInteraction(actorFor($this->owner), new DrugInteraction, ['ingredient_a' => 'Warfarin', 'ingredient_b' => 'Paracetamol', 'severity' => 'moderate', 'note' => 'Check INR']);
    expect(fn () => $svc->saveInteraction(actorFor($this->owner), new DrugInteraction, ['ingredient_a' => 'paracetamol', 'ingredient_b' => 'warfarin', 'severity' => 'minor', 'note' => 'x']))
        ->toThrow(DomainException::class, 'already')
        ->and(fn () => $svc->savePatient(actorFor($this->cashier), $this->ama, []))->toThrow(AccessDenied::class)
        ->and(DrugInteraction::sole()->ingredient_a)->toBe('paracetamol');

    $warnings = PharmacyService::warningsFor([$this->amox->id, $this->warf->id, $this->para->id], $this->ama->id);
    expect(collect($warnings)->pluck('kind')->all())->toBe(['allergy', 'interaction'])
        ->and($warnings[0]['message'])->toContain('allergic to amoxicillin')->and($warnings[1]['severity'])->toBe('moderate');

    rxSale($this, [[$this->warf, '1'], [$this->para, '1']], ['customer_id' => $this->ama->id, 'prescription' => $this->rx,
        'pharmacist_approval' => ['username' => 'kwame', 'pin' => '771204']]);
    expect(Prescription::sole()->warnings)->toHaveCount(1);
});

it('posts offline prescription sales as they happened and flags any without a pharmacist', function () {
    rxSale($this, [[$this->amox, '1']], ['customer_id' => $this->ama->id, 'prescription' => $this->rx], offline: true);
    expect(ReviewItem::where('type', 'pharmacy_unapproved')->count())->toBe(1)->and(Prescription::sole()->pharmacist_name)->toBe('Not approved');

    rxSale($this, [[$this->amox, '1']], ['customer_id' => $this->ama->id, 'prescription' => $this->rx, 'pharmacist_offline_by' => $this->pharmacist->id], offline: true);
    expect(ReviewItem::where('type', 'pharmacy_unapproved')->count())->toBe(1)
        ->and(Prescription::latest('number')->first()->approved_offline)->toBeTrue();

    rxSale($this, [[$this->amox, '1']], offline: true); // no patient at all
    expect(ReviewItem::where('type', 'pharmacy_unapproved')->count())->toBe(3)->and(Prescription::count())->toBe(2);
});

it('applies the prescription rule only where the pharmacy module is on', function () {
    app(ModuleService::class)->setStatus(actorFor($this->owner), $this->hq, 'pharmacy', 'disabled');
    rxSale($this, [[$this->amox, '1']]);
    expect(Prescription::count())->toBe(0);
});

it('gives pharmacy tills the drug details and interaction table, and allergies only to pharmacy staff', function () {
    app(PharmacyService::class)->savePatient(actorFor($this->owner), $this->ama, ['allergies' => 'amoxicillin']);
    $token = function (string $username) {
        [$device, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'X'.Str::random(3), 'kind' => 'pos']);
        $t = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->json('token');
        app('auth')->forgetGuards();
        $login = $this->withToken($t)->postJson('/api/v1/pos/login', ['username' => $username, 'pin' => $username === 'kwame' ? '771204' : '482613'])->json('token');
        app('auth')->forgetGuards();

        return $login;
    };
    $till = staff($this->owner, 'efua2', 2, 'teller');
    app(AccessService::class)->setPin(actorFor($this->owner), $till, '482613');

    $cashierToken = $token('efua2');
    $cat = $this->withToken($cashierToken)->getJson('/api/v1/pos/catalog')->assertOk()->json();
    expect($cat['pharmacy']['interactions'])->toBe([])->and($cat['pharmacy']['rules'])->toMatchArray(['pom' => 'approve', 'controlled' => 'approve', 'after' => 'signoff'])
        ->and(collect($cat['products'])->firstWhere('sku', 'AMX')['drug'])->toMatchArray(['schedule' => 'pom', 'active_ingredients' => ['amoxicillin']]);
    $ama = collect($this->withToken($cashierToken)->getJson('/api/v1/pos/customers')->assertOk()->json('customers'))->firstWhere('id', $this->ama->id);
    expect($ama)->not->toHaveKey('allergies');
    app('auth')->forgetGuards();

    $ama = collect($this->withToken($token('kwame'))->getJson('/api/v1/pos/customers')->assertOk()->json('customers'))->firstWhere('id', $this->ama->id);
    expect($ama['allergies'])->toBe(['amoxicillin']);
});

it('keeps the controlled-drug register from the ledger and exports NHIS claims', function () {
    $mor = makeProduct($this->owner, 'MOR', 'Morphine 10mg tabs', retail: '40.00');
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $mor, ['track_batches' => true, 'drug_schedule' => 'controlled', 'strength' => '10 mg', 'active_ingredients' => 'morphine']);
    $mor = $mor->fresh();
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $mor, $mor->units->first(), '20', 'opening', '', null, '10.00', 'M-1', now()->addYear()->toDateString());
    app(PharmacyService::class)->savePatient(actorFor($this->owner), $this->ama, ['nhis_no' => 'NHIS-778899', 'nhis_expires_on' => now()->addYear()->toDateString()]);
    $sale = rxSale($this, [[$mor->fresh(), '3'], [$this->para, '1']], ['customer_id' => $this->ama->id, 'prescription' => $this->rx,
        'pharmacist_approval' => ['username' => 'kwame', 'pin' => '771204']]);

    [$entry] = PharmacyReports::controlledRegister($this->wh->id, now()->subDay()->toDateString(), now()->toDateString());
    expect($entry['product']->sku)->toBe('MOR')->and($entry['opening'])->toBe('0.000')->and($entry['closing'])->toBe('17.000')
        ->and($entry['rows'][1]->patient)->toBe('AMA OWUSU')->and($entry['rows'][1]->rx_number)->toStartWith('RX-')->and($entry['rows'][1]->balance)->toBe('17.000');

    $this->actingAs($this->pharmacist);
    $this->get(route('print.controlled-register', ['warehouse' => $this->wh->id, 'from' => now()->toDateString(), 'to' => now()->toDateString()]))
        ->assertOk()->assertSee('CONTROLLED-DRUG REGISTER')->assertSee('AMA OWUSU');
    $csv = $this->get(route('print.nhis-claims', ['from' => now()->toDateString(), 'to' => now()->toDateString()]))->assertOk()->streamedContent();
    expect($csv)->toContain('NHIS-778899')->toContain('MORPHINE 10MG TABS')->not->toContain('PARACETAMOL') // only prescription lines are claimed
        ->and(DB::table('sale_lines')->where('sale_id', $sale->id)->whereNotNull('prescription_id')->count())->toBe(1);

    $this->flushSession();
    $this->actingAs($this->cashier)->get(route('print.nhis-claims'))->assertForbidden();
});

it('shows the pharmacy screens to pharmacy staff', function () {
    rxSale($this, [[$this->amox, '1']], ['customer_id' => $this->ama->id, 'prescription' => $this->rx, 'pharmacist_approval' => ['username' => 'kwame', 'pin' => '771204']]);
    $this->actingAs(withMfa($this->owner));
    foreach ([PrescriptionResource::getUrl('index'), PrescriptionResource::getUrl('view', ['record' => Prescription::sole()]), DrugInteractionResource::getUrl('index'),
        ControlledDrugRegister::getUrl(), ProductResource::getUrl('edit', ['record' => $this->amox])] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get(PrescriptionResource::getUrl('view', ['record' => Prescription::sole()]))->assertSee('Dr. K. Mensah');
    $this->flushSession();
    $this->actingAs(withMfa($this->cashier))->get(PrescriptionResource::getUrl('index'))->assertForbidden();
});

it('lets a branch allow tellers to sell prescription medicines, within the limits', function () {
    $settings = app(Settings::class);
    $settings->set(actorFor($this->owner), 'pharmacy.pom_rule', 'details');
    $settings->set(actorFor($this->owner), 'pharmacy.max_qty_without_pharmacist', 10);
    // Prescription details only: the teller sells; no patient or details is still refused.
    expect(fn () => rxSale($this, [[$this->amox, '1']]))->toThrow(DomainException::class, 'choose the patient');
    $sale = rxSale($this, [[$this->amox, '2']], ['customer_id' => $this->ama->id, 'prescription' => $this->rx]);
    expect(Prescription::find($sale->lines[0]->prescription_id)->pharmacist_id)->toBeNull();
    // Above the limit a pharmacist is needed.
    expect(fn () => rxSale($this, [[$this->amox, '11']], ['customer_id' => $this->ama->id, 'prescription' => $this->rx]))->toThrow(DomainException::class, 'pharmacist must approve');

    // This branch sells freely; the company stays strict for controlled drugs.
    app(OrgService::class)->updateBranch(actorFor($this->owner), $this->hq, ['settings' => ['pharmacy.pom_rule' => 'free']]);
    expect($settings->forBranch('pharmacy.pom_rule', $this->hq->fresh(), $this->tenant->id))->toBe('free')
        ->and($settings->forBranch('pharmacy.pom_rule', $this->branches['BA'], $this->tenant->id))->toBe('details');
    $free = rxSale($this, [[$this->amox, '1']]);
    expect($free->status)->toBe('posted');
    expect(fn () => app(OrgService::class)->updateBranch(actorFor($this->owner), $this->hq, ['settings' => ['pharmacy.pom_rule' => 'whatever']]))
        ->toThrow(DomainException::class);

    // A major warning still needs a pharmacist.
    app(PharmacyService::class)->saveInteraction(actorFor($this->owner), new DrugInteraction, ['ingredient_a' => 'amoxicillin', 'ingredient_b' => 'warfarin', 'severity' => 'major', 'note' => 'Bleeding risk']);
    expect(fn () => rxSale($this, [[$this->amox, '1'], [$this->warf, '1']], ['customer_id' => $this->ama->id, 'prescription' => $this->rx]))
        ->toThrow(DomainException::class, 'pharmacist must approve');
});

it('keeps controlled drugs strict when locked, and lists unapproved sales for a pharmacist to sign off', function () {
    $peth = makeProduct($this->owner, 'PTH', 'Pethidine 50mg', retail: '20.00');
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $peth, ['track_batches' => true, 'drug_schedule' => 'controlled']);
    $peth = $peth->fresh();
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $peth, $peth->units->first(), '20', 'opening', '', null, '5.00', 'PTH-1', now()->addYear()->toDateString());
    $settings = app(Settings::class);
    foreach (['pharmacy.pom_rule' => 'patient', 'pharmacy.controlled_rule' => 'free', 'pharmacy.controlled_always_strict' => true] as $k => $v) {
        $settings->set(actorFor($this->owner), $k, $v);
    }
    expect(app(PharmacyRules::class)->forBranch($this->hq)['controlled'])->toBe('approve');
    expect(fn () => rxSale($this, [[$peth, '1']], ['customer_id' => $this->ama->id, 'prescription' => $this->rx]))->toThrow(DomainException::class, 'pharmacist must approve');

    $sale = rxSale($this, [[$this->amox, '1']], ['customer_id' => $this->ama->id]); // patient only
    $approved = rxSale($this, [[$this->amox, '1']], ['customer_id' => $this->ama->id, 'pharmacist_approval' => ['username' => 'kwame', 'pin' => '771204']]);
    $range = [now()->subDay()->toDateTimeString(), now()->addDay()->toDateTimeString()];
    expect(PharmacyRules::unapproved($this->tenant->id, null, ...$range)->pluck('id')->all())->toBe([$sale->id]);

    expect(fn () => app(PharmacySignoffs::class)->sign(actorFor($this->cashier), [$sale->id]))->toThrow(AccessDenied::class);
    expect(app(PharmacySignoffs::class)->sign(actorFor($this->pharmacist), [$sale->id], 'Counselled'))->toBe(1)
        ->and(app(PharmacySignoffs::class)->sign(actorFor($this->pharmacist), [$sale->id]))->toBe(0);
    expect(PharmacyRules::unapproved($this->tenant->id, null, ...$range)->whereNull('so.id')->count())->toBe(0);
    $this->artisan('erp:pharmacy-signoffs')->assertSuccessful();
});

it('shows the sales to sign off and the branch pharmacy rules on screen', function () {
    app(Settings::class)->set(actorFor($this->owner), 'pharmacy.pom_rule', 'patient');
    $sale = rxSale($this, [[$this->amox, '1']], ['customer_id' => $this->ama->id]);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->pharmacist));
    Livewire::test(MedicineSignoffs::class)->assertSee($sale->receipt_no)->assertSee('AMOXICILLIN 500MG CAPS')
        ->callTableBulkAction('sign', [$sale->id], ['note' => 'Checked'])->assertNotified('1 sale(s) signed off');
    expect(DB::table('pharmacy_signoffs')->value('signed_by_name'))->toBe($this->pharmacist->name);

    $this->actingAs(withMfa($this->owner));
    Livewire::test(EditBranch::class, ['record' => $this->hq->id])
        ->assertSee('Pharmacy rules for this branch')->assertSee('Company: Patient only (the teller sells)')
        ->fillForm(['own_pharmacy__pom_rule' => 'free', 'reason' => 'No pharmacist on Sundays'])->call('save')->assertHasNoFormErrors();
    expect($this->hq->fresh()->settings)->toBe(['pharmacy.pom_rule' => 'free']);
});

/** A till sale the NHIS pays part of (the rest in cash). */
function nhisSale($t, array $items, int $nhisMinor, array $extra = []): Sale
{
    $lines = [];
    $engine = [];
    foreach ($items as $i => [$p, $qty]) {
        $unit = $p->units->first();
        $price = app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
        $engine[] = ['key' => (string) $i, 'product_id' => $p->id, 'category_id' => $p->category_id, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
            'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price];
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }
    $total = $calc['total'];
    $extra += ['totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $total]];
    $payments = array_values(array_filter([['method' => 'nhis', 'amount_minor' => $nhisMinor, 'tendered_minor' => $nhisMinor],
        $total > $nhisMinor ? ['method' => 'cash', 'amount_minor' => $total - $nhisMinor, 'tendered_minor' => $total - $nhisMinor] : null]));

    return app(SaleService::class)->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'lines' => $lines, 'payments' => $payments] + $extra)['sale'];
}

it('takes NHIS at the till for a patient with a valid card, up to the NHIS prices, and claims it from the NHIA', function () {
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->para, ['nhis_price_minor' => 400]); // NHIS pays 4.00 a tablet; we sell at 5.00
    $svc = app(PharmacyService::class);
    $svc->savePatient(actorFor($this->owner), $this->ama, ['nhis_no' => 'gh-123', 'nhis_expires_on' => now()->addYear()->toDateString()]);
    $items = [[$this->para->fresh(), '2']];

    expect(fn () => nhisSale($this, $items, 800, ['customer_id' => $this->ama->id]))->toThrow(DomainException::class, 'not taken at this branch');
    app(Settings::class)->set(actorFor($this->owner), 'pharmacy.nhis_at_till', true);
    expect(fn () => nhisSale($this, $items, 800))->toThrow(DomainException::class, 'NHIS number')
        ->and(fn () => nhisSale($this, $items, 900, ['customer_id' => $this->ama->id]))->toThrow(DomainException::class, 'at most');

    $sale = nhisSale($this, $items, 800, ['customer_id' => $this->ama->id]);
    $pay = $sale->payments->firstWhere('method', 'nhis');
    expect($sale->total_minor)->toBe(1000)->and($pay->amount_minor)->toBe(800)->and($pay->reference)->toBe('GH-123')->and($pay->status)->toBe('due_from_nhis');

    $svc->savePatient(actorFor($this->owner), $this->ama, ['nhis_no' => 'gh-123', 'nhis_expires_on' => now()->subDay()->toDateString()]);
    expect(fn () => nhisSale($this, $items, 800, ['customer_id' => $this->ama->id]))->toThrow(DomainException::class, 'expired');

    // The claim: prepared, sent, paid in part; the rest is written off.
    $claims = app(NhisClaims::class);
    $accountant = staff($this->owner, 'abena', 4, 'accountant');
    $nurse = staff($this->owner, 'yaa', 4, 'pharmacist', ScopeType::Branch, $this->hq->id);
    expect(fn () => $claims->prepare(actorFor($nurse), $this->hq, now()->toDateString()))->toThrow(AccessDenied::class);
    $claim = $claims->prepare(actorFor($accountant), $this->hq, now()->toDateString());
    expect($claim->claimed_minor)->toBe(800)->and($claim->number)->toStartWith('NHC-')->and($claim->lines()->count())->toBe(1)
        ->and(fn () => $claims->prepare(actorFor($accountant), $this->hq, now()->toDateString()))->toThrow(DomainException::class, 'Nothing to claim')
        ->and(fn () => $claims->settle(actorFor($accountant), $claim, 700, now()->toDateString(), 'bank'))->toThrow(DomainException::class, 'sent');
    $claims->submit(actorFor($accountant), $claim, 'NHIA/2026/10/55');
    expect(fn () => $claims->settle(actorFor($accountant), $claim->fresh(), 900, now()->toDateString(), 'bank'))->toThrow(DomainException::class, 'between');
    $claims->settle(actorFor($accountant), $claim->fresh(), 700, now()->toDateString(), 'bank', 'One line not on the list that month');
    expect($claim->fresh())->status->toBe('settled')->rejected_minor->toBe(100);

    app(AutoPoster::class)->run($this->tenant->id);
    $bal = fn (string $key) => (int) DB::table('journal_lines')->where('account_id', Account::where('system_key', $key)->value('id'))->sum('amount_minor');
    expect($bal('nhis_receivable'))->toBe(0)->and($bal('nhis_rejected'))->toBe(100)->and($bal('bank'))->toBe(700);

    // The back office: list, claim page with its sales, the CSV for the NHIA.
    $this->actingAs(withMfa($this->owner), 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get(NhisClaimResource::getUrl('index'))->assertOk()->assertSee($claim->number);
    Livewire::test(ViewNhisClaim::class, ['record' => $claim->id])->assertOk()->assertSee('NHIA/2026/10/55');
    $csv = $this->get(route('print.nhis-claim', ['claim' => $claim->id]))->assertOk()->streamedContent();
    expect($csv)->toContain('GH-123')->toContain('PARACETAMOL')->toContain('8.00');
});

it('withdraws a claim not sent yet, so its sales are claimed next time', function () {
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->para, ['nhis_price_minor' => 400]);
    app(PharmacyService::class)->savePatient(actorFor($this->owner), $this->ama, ['nhis_no' => 'GH-9']);
    app(Settings::class)->set(actorFor($this->owner), 'pharmacy.nhis_at_till', true);
    nhisSale($this, [[$this->para->fresh(), '1']], 400, ['customer_id' => $this->ama->id]);
    $claims = app(NhisClaims::class);
    $first = $claims->prepare(actorFor($this->owner), $this->hq, now()->toDateString());
    $claims->withdraw(actorFor($this->owner), $first, 'Wrong month');
    expect($first->fresh()->status)->toBe('withdrawn')
        ->and($claims->prepare(actorFor($this->owner), $this->hq, now()->toDateString())->claimed_minor)->toBe(400);
});

it('adds the starter list of interactions once, and warns from it', function () {
    $svc = app(PharmacyService::class);
    expect(fn () => $svc->addStarter(actorFor($this->cashier)))->toThrow(AccessDenied::class);
    DrugInteraction::query()->delete();
    $n = $svc->addStarter(actorFor($this->owner));
    expect($n)->toBe(count(InteractionStarter::ROWS))->and($svc->addStarter(actorFor($this->owner)))->toBe(0)
        ->and(DrugInteraction::where('source', 'starter')->count())->toBe($n);
    $ibu = makeProduct($this->owner, 'IBU', 'Ibuprofen 400', retail: '6.00');
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $ibu, ['drug_schedule' => 'otc', 'track_batches' => true, 'generic_name' => 'Ibuprofen']);
    $w = PharmacyService::warningsFor([$this->warf->id, $ibu->id], null);
    expect($w)->toHaveCount(1)->and($w[0]['severity'])->toBe('major');

    $this->actingAs(withMfa($this->owner), 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $nameless = makeProduct($this->owner, 'XYZ', 'Herbal mix', retail: '6.00');
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $nameless, ['drug_schedule' => 'otc', 'track_batches' => true]);
    $this->get(DrugInteractionResource::getUrl('index'))->assertOk()->assertSee('1 medicine(s) have neither a generic name nor active ingredients');
});

it('marks down near-expiry medicines from the Pharmacy menu, and the till gets the sticker', function () {
    $this->actingAs(withMfa($this->owner), 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->get(PharmacyMarkdowns::getUrl())->assertOk()->assertSee('Near-expiry markdowns');
    $batch = DB::table('stock_batches')->where('product_id', $this->para->id)->value('id');
    DB::table('stock_batches')->where('id', $batch)->update(['expires_on' => now()->addDays(5)->toDateString()]);
    $md = app(MarkdownService::class)->apply(actorFor($this->owner), $this->wh, $batch, 3000);
    expect(collect(MarkdownService::activeFor($this->hq->id))->pluck('code'))->toContain($md->code);
});
