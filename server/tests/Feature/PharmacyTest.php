<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Devices\OfflineGrant;
use App\Domain\Inventory\InventoryService;
use App\Domain\Modules\ModuleService;
use App\Domain\Pharmacy\PharmacyReports;
use App\Domain\Pharmacy\PharmacyService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ControlledDrugRegister;
use App\Filament\Resources\DrugInteractions\DrugInteractionResource;
use App\Filament\Resources\Prescriptions\PrescriptionResource;
use App\Filament\Resources\Products\ProductResource;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
    $this->cashier = staff($this->owner, 'efua', 2, 'cashier', ScopeType::Till, $this->till->id);
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
    $till = staff($this->owner, 'efua2', 2, 'cashier');
    app(AccessService::class)->setPin(actorFor($this->owner), $till, '482613');

    $cashierToken = $token('efua2');
    $cat = $this->withToken($cashierToken)->getJson('/api/v1/pos/catalog')->assertOk()->json();
    expect($cat['pharmacy'])->toBe(['interactions' => []])
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
        ->and($entry['rows'][1]->patient)->toBe('Ama Owusu')->and($entry['rows'][1]->rx_number)->toStartWith('RX-')->and($entry['rows'][1]->balance)->toBe('17.000');

    $this->actingAs($this->pharmacist);
    $this->get(route('print.controlled-register', ['warehouse' => $this->wh->id, 'from' => now()->toDateString(), 'to' => now()->toDateString()]))
        ->assertOk()->assertSee('CONTROLLED-DRUG REGISTER')->assertSee('Ama Owusu');
    $csv = $this->get(route('print.nhis-claims', ['from' => now()->toDateString(), 'to' => now()->toDateString()]))->assertOk()->streamedContent();
    expect($csv)->toContain('NHIS-778899')->toContain('Morphine 10mg tabs')->not->toContain('Paracetamol') // only prescription lines are claimed
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
