<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pharmacy\NhisClaims;
use App\Domain\Pharmacy\PharmacyService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Filament\Resources\NhisClaims\Pages\ViewNhisClaim;
use App\Filament\Resources\NhisClaims\RelationManagers\LinesRelationManager;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\NhisClaim;
use App\Models\PriceList;
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

// NHIS claims and medicines sold without a pharmacist in the new back office (pharmacy, part 12).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $o = actorFor($this->owner);
    enableModule($this->owner, $this->hq, 'pharmacy');
    withMfa($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $drug = function (string $sku, string $name, string $price, array $fields) use ($o) {
        $p = makeProduct($this->owner, $sku, $name, retail: $price);
        app(CatalogService::class)->saveProduct($o, $p, ['track_batches' => true] + $fields);
        $p = $p->fresh();
        app(InventoryService::class)->adjust($o, $this->wh, $p, $p->units->first(), '50', 'opening', '', null, '1.00', "{$sku}-1", now()->addYear()->toDateString());

        return $p->fresh();
    };
    $this->amox = $drug('AMX', 'Amoxicillin 500mg caps', '25.00', ['drug_schedule' => 'pom', 'generic_name' => 'amoxicillin', 'active_ingredients' => 'amoxicillin']);
    $this->para = $drug('PCM', 'Paracetamol 500mg tabs', '5.00', ['drug_schedule' => 'otc', 'generic_name' => 'paracetamol', 'active_ingredients' => 'paracetamol', 'nhis_price_minor' => 400]);
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($o, ['branch_id' => $this->hq->id, 'till_id' => $till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $till->id);
    $this->pharmacist = staff($this->owner, 'kwame', 4, 'pharmacist', ScopeType::Branch, $this->hq->id);
    app(AccessService::class)->setPin($o, $this->pharmacist, '771204');
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
    $this->patient = app(CustomerService::class)->save($o, new Customer, ['name' => 'Test Patient', 'phone' => '0241112222']);
});

/** A till sale; $nhis is the part the NHIS pays (the rest in cash). */
function officeTillSale($t, array $items, int $nhis = 0, array $extra = []): Sale
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
    $payments = array_values(array_filter([$nhis ? ['method' => 'nhis', 'amount_minor' => $nhis, 'tendered_minor' => $nhis] : null,
        $total > $nhis ? ['method' => 'cash', 'amount_minor' => $total - $nhis, 'tendered_minor' => $total - $nhis] : null]));

    return app(SaleService::class)->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'lines' => $lines, 'payments' => $payments,
        'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $total]] + $extra)['sale'];
}

it('prepares an NHIS claim, sends it and records what the NHIA paid', function () {
    app(Settings::class)->set(actorFor($this->owner), 'pharmacy.nhis_at_till', true);
    app(PharmacyService::class)->savePatient(actorFor($this->owner), $this->patient, ['nhis_no' => 'gh-123', 'nhis_expires_on' => now()->addYear()->toDateString()]);
    $sale = officeTillSale($this, [[$this->para, '2']], 800, ['customer_id' => $this->patient->id]);
    $this->actingAs($this->owner, 'web');
    $today = now()->toDateString();

    $list = $this->getJson('/office-api/nhis-claims')->assertOk()->json();
    expect($list['total'])->toBe(0)->and(collect($list['branches'])->pluck('id')->all())->toContain($this->hq->id);
    expect($this->getJson("/office-api/nhis-claims/unclaimed?branch={$this->hq->id}&up_to={$today}")->assertOk()->json())->toMatchArray(['sales' => 1, 'amount' => 800]);
    $id = $this->postJson('/office-api/nhis-claims', ['branch' => $this->hq->id, 'up_to' => $today])->assertOk()->json('id');
    $this->postJson('/office-api/nhis-claims', ['branch' => $this->hq->id, 'up_to' => $today])->assertStatus(422)->assertJsonPath('detail', fn ($d) => str_contains($d, 'Nothing to claim'));

    $c = $this->getJson("/office-api/nhis-claims/{$id}")->assertOk()->json();
    expect($c)->toMatchArray(['status' => 'open', 'claimed' => 800, 'paid' => null])->and($c['download'])->toContain('/print/nhis-claim/')
        ->and($c['lines']['rows'][0])->toMatchArray(['receipt' => $sale->receipt_no, 'patient' => 'TEST PATIENT', 'nhis_no' => 'GH-123', 'amount' => 800]);
    $this->postJson("/office-api/nhis-claims/{$id}/settle", ['paid' => '7', 'paid_on' => $today, 'method' => 'bank'])->assertStatus(422); // not sent yet
    $this->postJson("/office-api/nhis-claims/{$id}/submit", ['reference' => 'NHIA/REF/1'])->assertOk();
    $this->postJson("/office-api/nhis-claims/{$id}/withdraw", ['reason' => 'Wrong month'])->assertStatus(422); // already sent
    $this->postJson("/office-api/nhis-claims/{$id}/settle", ['paid' => '7.00', 'paid_on' => $today, 'method' => 'bank', 'note' => 'One line refused'])->assertOk();
    expect(NhisClaim::find($id))->status->toBe('settled')->paid_minor->toBe(700)->rejected_minor->toBe(100)->reference->toBe('NHIA/REF/1')
        ->and(AuditEvent::where('action', 'nhis_claim.settled')->count())->toBe(1);
    expect($this->getJson('/office-api/nhis-claims?status=settled')->json('rows.0'))->toMatchArray(['id' => $id, 'paid' => 700, 'rejected' => 100]);

    $this->flushSession();
    $this->actingAs(withMfa($this->pharmacist), 'web'); // dispenses, but does not claim from the NHIA
    $this->getJson('/office-api/nhis-claims')->assertForbidden();
    $this->postJson('/office-api/nhis-claims', ['branch' => $this->hq->id, 'up_to' => $today])->assertForbidden();
});

it('lists medicines sold without a pharmacist and lets a pharmacist sign them off', function () {
    app(Settings::class)->set(actorFor($this->owner), 'pharmacy.pom_rule', 'patient');
    $sale = officeTillSale($this, [[$this->amox, '1']], 0, ['customer_id' => $this->patient->id]);
    $this->actingAs(withMfa($this->pharmacist), 'web');

    $list = $this->getJson('/office-api/medicine-signoffs')->assertOk()->json();
    expect($list['can_sign'])->toBeTrue()->and($list['total'])->toBe(1)
        ->and($list['rows'][0])->toMatchArray(['id' => $sale->id, 'receipt' => $sale->receipt_no, 'medicines' => 'AMOXICILLIN 500MG CAPS', 'controlled' => false, 'signed_by' => null]);
    $this->postJson('/office-api/medicine-signoffs', ['ids' => [$sale->id], 'note' => 'Checked'])->assertOk()->assertJsonPath('message', '1 sale(s) signed off.');
    expect($this->getJson('/office-api/medicine-signoffs')->json('total'))->toBe(0)
        ->and($this->getJson('/office-api/medicine-signoffs?status=signed')->json('rows.0'))->toMatchArray(['signed_by' => $this->pharmacist->name, 'note' => 'Checked']);
    $csv = $this->get('/office-api/medicine-signoffs/csv?status=all')->assertOk()->streamedContent();
    expect($csv)->toContain($sale->receipt_no)->toContain('Checked');
    expect(DB::table('pharmacy_signoffs')->count())->toBe(1);

    $this->flushSession();
    $this->actingAs(withMfa($this->cashier), 'web');
    $this->getJson('/office-api/medicine-signoffs')->assertForbidden();
    $this->postJson('/office-api/medicine-signoffs', ['ids' => [$sale->id]])->assertForbidden();
});

it('shows the sales on a claim in the current screen too (it needed the sale\'s customer)', function () {
    app(Settings::class)->set(actorFor($this->owner), 'pharmacy.nhis_at_till', true);
    app(PharmacyService::class)->savePatient(actorFor($this->owner), $this->patient, ['nhis_no' => 'gh-123', 'nhis_expires_on' => now()->addYear()->toDateString()]);
    $sale = officeTillSale($this, [[$this->para, '2']], 800, ['customer_id' => $this->patient->id]);
    $claim = app(NhisClaims::class)->prepare(actorFor($this->owner), $this->hq, now()->toDateString());
    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(LinesRelationManager::class, ['ownerRecord' => $claim, 'pageClass' => ViewNhisClaim::class])->assertOk()
        ->assertSee($sale->receipt_no)->assertSee('TEST PATIENT')->assertSee('GH-123');
});
