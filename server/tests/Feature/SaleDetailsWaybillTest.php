<?php

use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\TransferService;
use App\Domain\Logistics\WaybillService;
use App\Domain\Org\OrgService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Support\DomainException;
use App\Filament\Resources\Sales\Pages\ViewSale;
use App\Filament\Resources\Waybills\Pages\ViewWaybill;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Sale;
use App\Models\StockTransfer;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00');
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
});

function detailSale($t, array $extra = []): Sale
{
    $unit = $t->milo->units->first();
    $engine = [['key' => '0', 'product_id' => $t->milo->id, 'category_id' => null, 'qty_milli' => 1000, 'unit_price_minor' => 1500,
        'tax' => $t->milo->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null]];
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    $payload = ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id,
        'lines' => [['product_id' => $t->milo->id, 'product_uom_id' => $unit->id, 'qty' => '1', 'unit_price_minor' => 1500, 'line_total_minor' => $calc['lines'][0]['total']]],
        'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]] + $extra;

    return app(SaleService::class)->post($t->device, $t->cashier, $payload)['sale'];
}

it('keeps a comment, a walk-in name and phone and industry details on the sale', function () {
    $s = detailSale($this, ['comment' => ' Gift wrap please ', 'comment_on_receipt' => true, 'walk_in' => ['name' => 'Kofi Boateng', 'phone' => '024 111 2222'],
        'extras' => ['age' => '34', 'sex' => 'male', 'weight_kg' => '70.25', 'unknown' => 'x']]);
    expect($s->comment)->toBe('Gift wrap please')->and($s->comment_on_receipt)->toBeTrue()->and($s->walk_in_name)->toBe('Kofi Boateng')
        ->and($s->walk_in_phone)->toBe('+233241112222')->and($s->extras)->toBe(['age' => 34, 'sex' => 'male', 'weight_kg' => 70.3]);
    expect(fn () => detailSale($this, ['extras' => ['age' => '200']]))->toThrow(DomainException::class, 'Age');

    // Saved as a customer from the back office; the sale itself stays as it was.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
    Livewire::test(ViewSale::class, ['record' => $s->id])->assertSee('Gift wrap please')->assertSee('Kofi Boateng')
        ->callAction('saveCustomer', ['name' => 'Kofi Boateng', 'phone' => '0241112222'])->assertNotified('Customer saved');
    expect(Customer::where('phone', '+233241112222')->exists())->toBeTrue()->and($s->fresh()->customer_id)->toBeNull();
    $this->get(route('print.receipt', $s))->assertOk()->assertSee('Gift wrap please')->assertSee('Kofi Boateng');
});

it('asks for the details a branch requires before payment', function () {
    app(OrgService::class)->updateBranch(actorFor($this->owner), $this->hq, ['settings' => ['pos.require_details' => 'name_phone']]);
    expect(fn () => detailSale($this))->toThrow(DomainException::class, 'name and phone');
    expect(fn () => detailSale($this, ['walk_in' => ['name' => 'Ama']]))->toThrow(DomainException::class, 'phone');
    expect(detailSale($this, ['walk_in' => ['name' => 'Ama', 'phone' => '0241234567']])->walk_in_name)->toBe('Ama');
});

it('prepares, dispatches and delivers a transfer waybill, confirmed by the receiver from the QR link', function () {
    $ba = Warehouse::where('branch_id', $this->branches['BA']->id)->first();
    $from = Warehouse::where('branch_id', $this->hq->id)->first();
    $t = app(TransferService::class)->saveDraft(actorFor($this->owner), new StockTransfer, ['from_warehouse_id' => $from->id, 'to_warehouse_id' => $ba->id,
        'lines' => [['product_uom_id' => $this->milo->units->first()->id, 'qty' => '12']]]);
    $svc = app(WaybillService::class);
    $w = $svc->prepare(actorFor($this->owner), 'transfer', $t->id, ['packages' => 2, 'vehicle_no' => 'gr 1234-24']);
    expect($w->number)->toStartWith('WB-')->and($w->lines[0])->toMatchArray(['sku' => 'MILO', 'qty' => '12.000'])->and($w->vehicle_no)->toBe('GR 1234-24')
        ->and($w->status)->toBe('prepared')->and($w->to_name)->toContain($ba->name);
    expect(fn () => $svc->prepare(actorFor($this->owner), 'transfer', $t->id, []))->toThrow(DomainException::class, 'already has a waybill');
    expect(fn () => $svc->dispatch(actorFor($this->owner), $w, []))->toThrow(DomainException::class, 'driver');
    $w = $svc->dispatch(actorFor($this->owner), $w, ['driver_name' => 'Yaw', 'driver_phone' => '0200000000']);
    expect($w->status)->toBe('dispatched');

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner));
    $this->get(route('print.waybill', $w))->assertOk()->assertSee('WAYBILL')->assertSee('<svg', false)->assertSee('MILO 400G');
    Livewire::test(ViewWaybill::class, ['record' => $w->id])->assertSee($w->number);
    auth()->logout();

    $token = DB::table('waybills')->where('id', $w->id)->value('confirm_token');
    $this->get(route('waybill.confirm', $token))->assertOk()->assertSee('Confirm received');
    $this->post(route('waybill.confirm', $token), ['name' => 'Akosua (BA store)', 'note' => 'All good'])->assertOk()->assertSee('Thank you');
    $w->refresh();
    expect($w->status)->toBe('delivered')->and($w->received_by_name)->toBe('Akosua (BA store)')->and($w->pod_via)->toBe('link')
        ->and(DB::table('waybills')->where('id', $w->id)->value('confirm_token'))->toBeNull();
    $this->get(route('waybill.confirm', $token))->assertNotFound();
    expect(DB::table('audit_events')->where('action', 'waybill.delivered')->count())->toBe(1);
});
