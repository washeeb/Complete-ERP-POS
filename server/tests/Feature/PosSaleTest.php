<?php

use App\Domain\Access\AccessService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\TaxCalculator;
use App\Filament\Resources\Devices\DeviceResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Sales\SaleResource;
use App\Filament\Resources\Shifts\Pages\ListShifts;
use App\Filament\Resources\Shifts\ShiftResource;
use App\Filament\Resources\StockLevels\StockLevelResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\TaxRates\TaxRateResource;
use App\Models\AuditEvent;
use App\Models\Customer;
use App\Models\OutboxEvent;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\RoleAssignment;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\Authorizer;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $this->wh = Warehouse::find($this->till->warehouse_id);
    $this->retail = PriceList::where('code', 'RETAIL')->first();

    $this->milo = makeProduct($this->owner, 'MILO-400', 'Milo 400g', retail: '45.00', carton: ['24', '1000.00'], wholesale: '42.50');
    $this->bread = makeProduct($this->owner, 'BREAD', 'Sliced bread', retail: '18.50', tax: 'EXEMPT');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->milo, $this->milo->units->firstWhere('is_base', true), '100', 'opening', '');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->bread, $this->bread->units->first(), '20', 'opening', '');

    [$device, $code] = app(DeviceService::class)->register(actorFor($this->owner),
        ['branch_id' => $this->branches['HQ']->id, 'till_id' => $this->till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $this->device = $device;
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $this->cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->cashier, '4826');
    $this->token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();
    $this->shiftId = $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 20000])
        ->assertCreated()->json('shift.id');
    $this->seq = 0;
});

/** Build a sale exactly as the till would: price × qty, tax, totals. */
function cart($t, array $items, ?array $payments = null, ?string $list = null): array
{
    $list = $list ? PriceList::where('code', $list)->first() : $t->retail;
    $lines = [];
    $net = $tax = $total = 0;
    foreach ($items as [$product, $unitIsBase, $qty]) {
        $unit = $product->units->firstWhere('is_base', $unitIsBase);
        $price = app(PriceResolver::class)->resolve($list, $product->id, $unit->id, Qty::toMilli($qty), now());
        $lineTotal = Qty::divRound(Qty::toMilli($qty) * $price->amount_minor, 1000);
        $rates = $product->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->all();
        $calc = TaxCalculator::line($lineTotal, $rates, $list->prices_include_tax);
        $lines[] = ['product_id' => $product->id, 'product_uom_id' => $unit->id, 'qty' => $qty,
            'unit_price_minor' => $price->amount_minor, 'line_total_minor' => $calc['gross']];
        $net += $calc['net'];
        $tax += $calc['tax'];
        $total += $calc['gross'];
    }

    return [
        'id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $list->id, 'shift_id' => $t->shiftId, 'lines' => $lines,
        'totals' => ['net_minor' => $net, 'tax_minor' => $tax, 'total_minor' => $total],
        'payments' => $payments ?? [['method' => 'cash', 'amount_minor' => $total, 'tendered_minor' => (int) ceil($total / 1000) * 1000]],
    ];
}

function onHand(Product $p, Warehouse $wh): string
{
    return (string) StockLevel::where('warehouse_id', $wh->id)->where('product_id', $p->id)->value('on_hand');
}

it('posts a cash sale: receipt number, tax, change, stock drops, audit and outbox written', function () {
    $sale = cart($this, [[$this->milo, true, '2'], [$this->bread, true, '1'], [$this->milo, false, '1']]);

    $r = $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sale)->assertCreated()->json('sale');

    expect($r['receipt_no'])->toBe('HQ-T01A-000001')
        ->and($r['total_minor'])->toBe(9000 + 1850 + 100000)
        ->and($r['change_minor'])->toBe($sale['payments'][0]['tendered_minor'] - $r['total_minor'])
        ->and(collect($r['tax_breakdown'])->sum('amount_minor'))->toBe($r['tax_minor'])
        ->and(onHand($this->milo, $this->wh))->toBe('74.000')      // 100 − 2 − 24
        ->and(onHand($this->bread, $this->wh))->toBe('19.000')
        ->and(StockMovement::where('source_id', $r['id'])->count())->toBe(3)
        ->and(AuditEvent::where('action', 'sale.posted')->where('device_id', $this->device->id)->exists())->toBeTrue()
        ->and(OutboxEvent::where('event_type', 'sale.posted')->where('aggregate_id', $r['id'])->exists())->toBeTrue()
        ->and(app(StockLedger::class)->check())->toBe([]);
});

it('treats a resent sale as the same sale (no double stock movement)', function () {
    $sale = cart($this, [[$this->milo, true, '1']]);
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sale)->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sale)->assertOk()->assertJson(['replayed' => true]);

    expect(Sale::count())->toBe(1)->and(onHand($this->milo, $this->wh))->toBe('99.000');
});

it('refuses a different sale reusing an id or a receipt number', function () {
    $sale = cart($this, [[$this->milo, true, '1']]);
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sale)->assertCreated();

    $tampered = $sale;
    $tampered['lines'][0]['qty'] = '2';
    $tampered['lines'][0]['line_total_minor'] = 9000;
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', $tampered)->assertStatus(409)->assertJson(['error' => 'conflict']);

    $sameSeq = cart($this, [[$this->bread, true, '1']]);
    $sameSeq['device_seq'] = 1;
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sameSeq)->assertStatus(409);
});

it('rejects a sale whose price changed since the till loaded it', function () {
    $sale = cart($this, [[$this->milo, true, '1']]);
    $sale['lines'][0]['unit_price_minor'] = 4000;
    $sale['lines'][0]['line_total_minor'] = 4000;

    $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sale)->assertStatus(422)->assertJson(['error' => 'price_changed']);
    expect(onHand($this->milo, $this->wh))->toBe('100.000');
});

it('rejects a till total that differs from the server by one pesewa', function () {
    $sale = cart($this, [[$this->milo, true, '1']]);
    $sale['totals']['tax_minor']++;
    $sale['totals']['net_minor']--;

    $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sale)->assertStatus(422)->assertJson(['error' => 'totals_mismatch']);
});

it('takes split cash + mobile money, and requires the MoMo transaction id and network', function () {
    $sale = cart($this, [[$this->milo, true, '2']]);
    $total = $sale['totals']['total_minor'];
    $sale['payments'] = [
        ['method' => 'cash', 'amount_minor' => 5000, 'tendered_minor' => 5000],
        ['method' => 'momo', 'provider' => 'mtn', 'amount_minor' => $total - 5000, 'reference' => '', 'payer_phone' => '024 123 4567'],
    ];
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sale)->assertStatus(422);

    $sale['payments'][1]['reference'] = '58123456789';
    $r = $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sale)->assertCreated()->json('sale');

    expect(collect($r['payments'])->firstWhere('method', 'momo')['status'])->toBe('confirmed_by_cashier')
        ->and($r['change_minor'])->toBe(0);
});

it('requires payments to equal the total', function () {
    $sale = cart($this, [[$this->milo, true, '1']]);
    $sale['payments'] = [['method' => 'cash', 'amount_minor' => 1000, 'tendered_minor' => 1000]];

    $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sale)->assertStatus(422)->assertJson(['error' => 'payment_mismatch']);
});

it('sells on the wholesale list when the cashier switches to it', function () {
    $r = $this->withToken($this->token)->postJson('/api/v1/pos/sales', cart($this, [[$this->milo, true, '10']], list: 'WHOLESALE'))
        ->assertCreated()->json('sale');

    expect($r['total_minor'])->toBe(42500);
});

it('can block sales below zero stock when the company chooses to', function () {
    app(Settings::class)->set(actorFor($this->owner), 'stock.block_negative_sales', true);

    $this->withToken($this->token)->postJson('/api/v1/pos/sales', cart($this, [[$this->bread, true, '25']]))
        ->assertStatus(422)->assertJson(['error' => 'insufficient_stock']);
});

it('sells into negative stock by default and shows the shortfall', function () {
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', cart($this, [[$this->bread, true, '25']]))->assertCreated();

    expect(onHand($this->bread, $this->wh))->toBe('-5.000');
});

it('closes the shift blind and works out over/short from the float and cash sales', function () {
    $sale = cart($this, [[$this->bread, true, '2']]); // 37.00 cash
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sale)->assertCreated();

    $open = $this->withToken($this->token)->getJson('/api/v1/pos/bootstrap')->assertOk()->json('shift');
    expect($open['expected_cash_minor'])->toBeNull(); // blind

    $closed = $this->withToken($this->token)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 20000 + 3700 - 200, 'note' => 'GH₵2 short'])
        ->assertOk()->json('shift');

    expect($closed['expected_cash_minor'])->toBe(23700)->and($closed['over_short_minor'])->toBe(-200);
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', cart($this, [[$this->bread, true, '1']]))
        ->assertStatus(422)->assertJson(['error' => 'no_shift']);
});

it('gives the till its catalog with units, barcodes, prices, tax and stock', function () {
    $cat = $this->withToken($this->token)->getJson('/api/v1/pos/catalog')->assertOk()->json('products');
    $milo = collect($cat)->firstWhere('sku', 'MILO-400');

    expect($milo['units'])->toHaveCount(2)
        ->and($milo['on_hand'])->toBe('100.000')
        ->and(collect($milo['tax'])->sum('rate_bp'))->toBe(2000)
        ->and(collect($milo['prices'])->pluck('amount_minor')->all())->toContain(4500, 100000, 4250);
});

it('refuses selling for someone without till access there', function () {
    // Cashier scoped to another branch's till cannot even sign in here; a revoked grant stops selling mid-shift.
    $grant = RoleAssignment::where('user_id', $this->cashier->id)->first();
    app(AccessService::class)->revoke(actorFor($this->owner), $grant, 'Moved branch');
    app(Authorizer::class)->forget();

    $this->withToken($this->token)->postJson('/api/v1/pos/sales', cart($this, [[$this->bread, true, '1']]))->assertForbidden();
});

it('shows the sale, its ledger lines and its figures in the back office', function () {
    $r = $this->withToken($this->token)->postJson('/api/v1/pos/sales', cart($this, [[$this->milo, true, '2']]))->assertCreated()->json('sale');
    app('auth')->forgetGuards();
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    // The API calls above switched the default guard to sanctum; the back office uses web.
    $this->withoutToken()->actingAs(withMfa($this->owner), 'web');

    // Values must be rendered, not just the page: catches display callbacks that receive null.
    $this->get(StockMovementResource::getUrl())->assertOk()
        ->assertSee('Sale HQ-T01A-000001')->assertSee('-2');
    $this->get(SaleResource::getUrl('view', ['record' => $r['id']]))->assertOk()
        ->assertSee('GH₵ 90.00')->assertSee('MILO 400G')->assertSee('Cash');
    $this->get(SaleResource::getUrl())->assertOk()->assertSee('GH₵ 90.00');
    $this->get(ShiftResource::getUrl())->assertOk()->assertSee('GH₵ 200.00');
    $this->get(TaxRateResource::getUrl())->assertOk()->assertSee('15.00%');
    $this->get(RoleResource::getUrl())->assertOk()->assertSee('L6');
    $this->get(DeviceResource::getUrl())->assertOk()->assertSee('POS till');
    $this->get(StockLevelResource::getUrl())->assertOk()->assertSee('98 PC');
});

it('keeps posted sale lines immutable at the database', function () {
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', cart($this, [[$this->bread, true, '1']]))->assertCreated();

    DB::table('sale_lines')->update(['unit_price_minor' => 1]);
})->throws(Exception::class, 'immutable');

it('lists customers for the till with their credit snapshot, and answers 304 when nothing changed', function () {
    $c = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'KOFI PROVISIONS', 'phone' => '0245551234',
        'price_list_id' => PriceList::where('code', 'WHOLESALE')->value('id'), 'credit_limit_minor' => 500_000, 'payment_terms_days' => 30]);
    app('auth')->forgetGuards();

    $r = $this->withToken($this->token)->getJson('/api/v1/pos/customers')->assertOk();
    expect($r->json('customers.0'))->toMatchArray(['name' => 'KOFI PROVISIONS', 'phone' => '+233245551234', 'credit_limit_minor' => 500_000,
        'available_minor' => 500_000, 'overdue_minor' => 0, 'credit_hold' => false])
        ->and(collect($this->withToken($this->token)->getJson('/api/v1/pos/bootstrap')->json('price_lists'))->pluck('code'))->toContain('WHOLESALE');
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson('/api/v1/pos/customers', ['If-None-Match' => '"'.$r->json('version').'"'])->assertStatus(304);
});

it('tells the till whether to show stock left while selling (off until switched on)', function () {
    $this->withToken($this->token)->getJson('/api/v1/pos/bootstrap')->assertOk()->assertJsonPath('show_stock', false);
    app(Settings::class)->set(actorFor($this->owner), 'pos.show_stock', true);
    app('auth')->forgetGuards();
    $this->withToken($this->token)->getJson('/api/v1/pos/bootstrap')->assertOk()->assertJsonPath('show_stock', true);
});

it('checks mobile money per network and the card terminal at the close, and shows them on Till shifts', function () {
    $pay = function (array $payments) {
        $sale = cart($this, [[$this->bread, true, '2']]); // 37.00
        $sale['payments'] = $payments;
        $this->withToken($this->token)->postJson('/api/v1/pos/sales', $sale)->assertCreated();
    };
    $pay([['method' => 'momo', 'provider' => 'mtn', 'amount_minor' => 3700, 'reference' => 'MTN0001']]);
    $pay([['method' => 'momo', 'provider' => 'mtn', 'amount_minor' => 3700, 'reference' => 'MTN0002']]);
    $pay([['method' => 'momo', 'provider' => 'telecel', 'amount_minor' => 3700, 'reference' => 'TEL0001']]);
    $pay([['method' => 'cash', 'amount_minor' => 1700, 'tendered_minor' => 1700], ['method' => 'card', 'amount_minor' => 2000, 'reference' => 'C1']]);

    // MTN: the phone shows one payment fewer; Telecel matches; card not checked.
    $closed = $this->withToken($this->token)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 21700, 'other' => ['momo:mtn' => 3700, 'momo:telecel' => 3700]])
        ->assertOk()->json('shift');
    expect($closed['over_short_minor'])->toBe(0)
        ->and(collect($closed['other'])->keyBy('key')->map(fn ($c) => [$c['expected'], $c['counted'], $c['diff']])->all())->toBe([
            'momo:mtn' => [7400, 3700, -3700], 'momo:telecel' => [3700, 3700, 0], 'card' => [2000, null, null],
        ]);

    $shift = Shift::find($this->shiftId);
    expect(ShiftResource::otherSummary($shift))->toBe('MTN MoMo short GH₵ 37.00');
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    app('auth')->forgetGuards(); // the till's token, not the back-office user
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire\Livewire::test(ListShifts::class)->assertSee('MTN MoMo short')
        ->callTableAction('report', $shift->id)->assertFileDownloaded();
    app('auth')->forgetGuards();

    $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0])->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 0, 'other' => ['bitcoin' => 5]])->assertStatus(422);
});
