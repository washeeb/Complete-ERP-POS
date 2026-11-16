<?php

use App\Domain\Access\AccessService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\TaxCalculator;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Sale;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use Illuminate\Support\Str;

// Till sales in the new back office (release 2): the same search, filters and detail as the current screen, only for the
// branches the person may see.

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->milo = makeProduct($this->owner, 'MILO-400', 'Milo 400g', retail: '45.00');
    app(InventoryService::class)->adjust(actorFor($this->owner), Warehouse::find($this->till->warehouse_id), $this->milo, $this->milo->units->firstWhere('is_base', true), '100', 'opening', '');
    [, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->branches['HQ']->id, 'till_id' => $this->till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $this->cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->cashier, '4826');
    $token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();
    $shift = $this->withToken($token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 20000])->assertCreated()->json('shift.id');
    $seq = 0;
    // Two sales: 2 Milo in cash, 1 Milo by mobile money for a walk-in who gave a name and phone.
    $sell = function (string $qty, array $pay, array $extra = []) use ($token, $shift, &$seq) {
        $unit = $this->milo->units->firstWhere('is_base', true);
        $price = app(PriceResolver::class)->resolve($this->retail, $this->milo->id, $unit->id, Qty::toMilli($qty), now());
        $gross = TaxCalculator::line(Qty::divRound(Qty::toMilli($qty) * $price->amount_minor, 1000),
            $this->milo->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->all(), $this->retail->prices_include_tax);
        $body = ['id' => (string) Str::uuid7(), 'device_seq' => ++$seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $this->retail->id, 'shift_id' => $shift,
            'lines' => [['product_id' => $this->milo->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price->amount_minor, 'line_total_minor' => $gross['gross']]],
            'totals' => ['net_minor' => $gross['net'], 'tax_minor' => $gross['tax'], 'total_minor' => $gross['gross']],
            'payments' => [$pay + ['amount_minor' => $gross['gross']]]] + $extra;

        return $this->withToken($token)->postJson('/api/v1/pos/sales', $body)->assertCreated()->json('sale');
    };
    $this->cash = $sell('2', ['method' => 'cash', 'tendered_minor' => 10000]);
    $this->momo = $sell('1', ['method' => 'momo', 'provider' => 'mtn', 'reference' => 'MP123'], ['walk_in' => ['name' => 'Kofi Mensah', 'phone' => '0244111222']]);
    $this->phone = CustomerService::normalisePhone('0244111222'); // kept in one standard form
    app('auth')->forgetGuards();
    $this->owner = withMfa($this->owner->fresh());
});

it('lists the till sales newest first, with totals and how they were paid', function () {
    $this->actingAs($this->owner, 'web');
    $r = $this->getJson('/office-api/sales')->assertOk()->json();
    expect($r['total'])->toBe(2)
        ->and($r['sum'])->toBe($this->cash['total_minor'] + $this->momo['total_minor'])
        ->and($r['rows'][0]['id'])->toBe($this->momo['id']) // newest first
        ->and($r['rows'][0]['paid_by'])->toBe(['Mobile money'])
        ->and(collect($r['mix'])->pluck('method'))->toContain('Cash')->toContain('Mobile money');
    expect($this->getJson('/office-api/sales/options')->assertOk()->json('tellers.0.label'))->toBe($this->cashier->name);
});

it('searches and filters as the current screen does: receipt, paid by, amount, dates, teller', function () {
    $this->actingAs($this->owner, 'web');
    $ids = fn (array $q) => collect($this->getJson('/office-api/sales?'.http_build_query($q))->assertOk()->json('rows'))->pluck('id')->all();
    expect($ids(['q' => $this->cash['receipt_no']]))->toBe([$this->cash['id']])
        ->and($ids(['methods' => ['momo']]))->toBe([$this->momo['id']])
        ->and($ids(['min' => '60']))->toBe([$this->cash['id']]) // 2 × 45.00 = 90.00
        ->and($ids(['period' => 'today']))->toHaveCount(2)
        ->and($ids(['from' => now()->addDay()->toDateString()]))->toBe([])
        ->and($ids(['teller' => $this->cashier->id, 'sort' => 'total_minor', 'dir' => 'asc']))->toBe([$this->momo['id'], $this->cash['id']]);
});

it('shows one sale in full, and keeps a walk-in as a customer', function () {
    $this->actingAs($this->owner, 'web');
    $s = $this->getJson("/office-api/sales/{$this->momo['id']}")->assertOk()->json();
    expect($s['lines'][0]['name'])->toBe('MILO 400G') // product names are kept in capitals
        ->and($s['payments'][0]['method'])->toBe('Mobile money · MTN MoMo')
        ->and($s['customer']['walk_in_phone'])->toBe($this->phone)
        ->and($s['actions']['save_customer'])->toBeTrue();
    $this->postJson("/office-api/sales/{$this->momo['id']}/save-customer", ['name' => 'Kofi Mensah', 'phone' => $this->phone])->assertCreated();
    expect(Customer::where('phone', $this->phone)->exists())->toBeTrue();
    expect($this->getJson("/office-api/sales/{$this->momo['id']}")->json('actions.save_customer'))->toBeFalse(); // already a customer
});

it('keeps sales to the branches the person may see', function () {
    $other = collect($this->branches)->first(fn ($b) => $b->code !== 'HQ');
    expect($other)->not->toBeNull(); // the test company has more than one branch
    $this->actingAs(withMfa(staff($this->owner, 'ama', 4, 'branch_manager', ScopeType::Branch, $other->id)), 'web');
    expect($this->getJson('/office-api/sales')->assertOk()->json('total'))->toBe(0);
    $this->getJson("/office-api/sales/{$this->cash['id']}")->assertForbidden();
    expect(Sale::count())->toBe(2);
});
