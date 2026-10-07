<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Devices\DeviceService;
use App\Domain\Devices\OfflineGrant;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Hr\HrService;
use App\Domain\Hr\PayrollService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Modules\ModuleService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Restaurant\DiningService;
use App\Domain\Restaurant\RestaurantSetup;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\RestaurantReport;
use App\Filament\Resources\DiningOrders\DiningOrderResource;
use App\Filament\Resources\DiningTables\DiningTableResource;
use App\Filament\Resources\KitchenStations\KitchenStationResource;
use App\Filament\Resources\ModifierGroups\ModifierGroupResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Account;
use App\Models\DiningOrder;
use App\Models\DiningOrderItem;
use App\Models\DiningTable;
use App\Models\Employee;
use App\Models\KitchenStation;
use App\Models\ModifierGroup;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ReviewItem;
use App\Models\Sale;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    enableModule($this->owner, $this->hq, 'restaurant');
    $setup = app(RestaurantSetup::class);
    $o = actorFor($this->owner);
    $setup->saveStation($o, new KitchenStation, ['branch_id' => $this->hq->id, 'code' => 'kitchen', 'name' => 'Main kitchen']);
    $setup->saveStation($o, new KitchenStation, ['branch_id' => $this->hq->id, 'code' => 'BAR', 'name' => 'Bar']);
    $this->t1 = $setup->saveTable($o, new DiningTable, ['branch_id' => $this->hq->id, 'code' => 't1', 'area' => 'Inside', 'seats' => 4]);
    $this->t2 = $setup->saveTable($o, new DiningTable, ['branch_id' => $this->hq->id, 'code' => 'T2', 'area' => 'Terrace', 'seats' => 2]);

    // Ingredients (stocked) and dishes (not stocked; their recipes use the ingredients).
    $inv = app(InventoryService::class);
    $this->rice = makeProduct($this->owner, 'RICE', 'Rice (kg)', retail: '20.00');
    $this->chicken = makeProduct($this->owner, 'CHK', 'Chicken portion', retail: '30.00');
    $this->cheese = makeProduct($this->owner, 'CHS', 'Extra cheese', retail: '5.00');
    foreach ([[$this->rice, '10'], [$this->chicken, '40']] as [$p, $q]) {
        $inv->adjust($o, $this->wh, $p, $p->units->first(), $q, 'opening', '', null, '10.00');
    }
    $this->cooking = $setup->saveModifierGroup($o, new ModifierGroup, ['name' => 'Spice', 'min_choices' => 1, 'max_choices' => 1,
        'options' => [['name' => 'Mild'], ['name' => 'Hot']]]);
    $this->extras = $setup->saveModifierGroup($o, new ModifierGroup, ['name' => 'Extras', 'min_choices' => 0, 'max_choices' => 2,
        'options' => [['name' => 'Extra cheese', 'product_id' => $this->cheese->id], ['name' => 'No onions']]]);
    $catalog = app(CatalogService::class);
    $this->jollof = makeProduct($this->owner, 'JOL', 'Jollof with chicken', retail: '60.00');
    $catalog->saveProduct($o, $this->jollof, ['track_stock' => false, 'station' => 'kitchen', 'modifier_group_ids' => [$this->cooking->id, $this->extras->id],
        'recipe' => [['product_uom_id' => $this->rice->units->first()->id, 'qty' => '0.25'], ['product_uom_id' => $this->chicken->units->first()->id, 'qty' => '1']]]);
    $this->jollof = $this->jollof->fresh();
    $this->beer = makeProduct($this->owner, 'BEER', 'Club beer', retail: '15.00');
    $catalog->saveProduct($o, $this->beer, ['track_stock' => false, 'station' => 'BAR']);
    $this->beer = $this->beer->fresh();
    $svc = makeProduct($this->owner, 'SERVICE', 'Service charge', retail: '0.00');
    $catalog->saveProduct($o, $svc, ['track_stock' => false]);
    $this->service = $svc->fresh();
    app(Settings::class)->set(actorFor($this->owner), 'restaurant.service_charge_bp', 1000);

    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($o, ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->waiter = staff($this->owner, 'ama', 2, 'waiter', ScopeType::Branch, $this->hq->id);
    $this->supervisor = staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->hq->id);
    app(AccessService::class)->setPin($o, $this->supervisor, '771204');
    $this->shift = app(ShiftService::class)->open($this->device, $this->waiter, 0);
    $this->seq = 0;
    $this->dining = app(DiningService::class);
});

function optionId(ModifierGroup $g, string $name): string
{
    return $g->options->firstWhere('name', $name)->id;
}

/** Pay bill items (or plain lines) at the till: lines [product, qty, extra line fields]; service charge and tip as asked. */
function restSale($t, array $items, array $extra = [], bool $service = false, int $tip = 0, bool $offline = false): Sale
{
    $lines = [];
    $engine = [];
    foreach ($items as $i => $it) {
        [$p, $qty] = $it;
        $unit = $p->units->first();
        $price = app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
        $tax = $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all();
        $engine[] = ['key' => (string) $i, 'product_id' => $p->id, 'category_id' => $p->category_id, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price, 'tax' => $tax, 'manual' => null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price] + ($it[2] ?? []);
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }
    [$net, $taxT, $total] = [$calc['net'], $calc['tax'], $calc['total']];
    if ($service) {
        $amount = Qty::divRound($total * 1000, 10000);
        $sv = TaxCalculator::line($amount, $t->service->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), true);
        $lines[] = ['product_id' => $t->service->id, 'product_uom_id' => $t->service->units->first()->id, 'qty' => '1', 'unit_price_minor' => $amount,
            'line_total_minor' => $sv['gross'], 'service_charge' => true];
        [$net, $taxT, $total] = [$net + $sv['net'], $taxT + $sv['tax'], $total + $sv['gross']];
    }
    $payload = ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $t->retail->id,
        'shift_id' => $t->shift->id, 'lines' => $lines, 'totals' => ['net_minor' => $net, 'tax_minor' => $taxT, 'total_minor' => $total], 'tip_minor' => $tip,
        'payments' => [['method' => 'cash', 'amount_minor' => $total + $tip, 'tendered_minor' => $total + $tip]]] + $extra;
    if ($offline) {
        $grant = app(OfflineGrant::class)->issue($t->waiter, $t->device, '482613');

        return app(SaleService::class)->post($t->device, null, $payload, app(OfflineGrant::class)->verify($grant))['sale'];
    }

    return app(SaleService::class)->post($t->device, $t->waiter, $payload)['sale'];
}

it('takes a table order with modifiers and courses to the kitchen screen and back', function () {
    $order = $this->dining->open($this->device, $this->waiter, ['id' => (string) Str::uuid7(), 'kind' => 'table', 'dining_table_id' => $this->t1->id, 'covers' => 2]);
    expect($order->number)->toStartWith('ORD-')
        ->and(fn () => $this->dining->open($this->device, $this->waiter, ['id' => (string) Str::uuid7(), 'kind' => 'table', 'dining_table_id' => $this->t1->id]))
        ->toThrow(DomainException::class, 'already has an open bill');

    $item = fn (array $over) => $over + ['id' => (string) Str::uuid7(), 'product_id' => $this->jollof->id, 'product_uom_id' => $this->jollof->units->first()->id, 'qty' => '1'];
    expect(fn () => $this->dining->addItems($this->device, $this->waiter, $order, [$item([])]))->toThrow(DomainException::class, 'choose 1 for Spice');
    $order = $this->dining->addItems($this->device, $this->waiter, $order, [
        $item(['modifier_option_ids' => [optionId($this->cooking, 'Hot'), optionId($this->extras, 'Extra cheese')], 'seat' => 1]),
        $item(['modifier_option_ids' => [optionId($this->cooking, 'Mild')], 'course' => 2, 'hold' => true, 'note' => 'well done']),
        ['id' => (string) Str::uuid7(), 'product_id' => $this->beer->id, 'product_uom_id' => $this->beer->units->first()->id, 'qty' => '2'],
    ]);
    expect($order->items->pluck('status')->all())->toBe(['fired', 'held', 'fired'])
        ->and($order->items[0]->modifiers)->toHaveCount(2)->and($order->items[0]->station)->toBe('KITCHEN');

    // Kitchen screen (its own device token): two stations, the held main course not shown yet.
    [$kds, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->hq->id, 'name' => 'Pass', 'kind' => 'kds']);
    $token = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->json('token');
    $tickets = fn (?string $station = null) => $this->withToken($token)->getJson('/api/v1/kds/tickets'.($station ? "?station={$station}" : ''))->assertOk()->json('tickets');
    expect($tickets()[0]['items'])->toHaveCount(2)->and($tickets('BAR')[0]['items'][0]['name'])->toBe('Club beer')
        ->and($tickets('KITCHEN')[0]['items'][0]['modifiers'][0]['name'])->toBe('Hot');

    $this->dining->fire($this->device, $this->waiter, $order, 2);
    $hot = $order->items[0]->id;
    $this->withToken($token)->postJson('/api/v1/kds/bump', ['item_ids' => [$hot], 'status' => 'ready'])->assertOk()->assertJson(['changed' => 1]);
    expect(DiningOrderItem::find($hot)->status)->toBe('ready')->and(count($tickets('KITCHEN')[0]['items']))->toBe(2);
    $this->dining->serve($this->device, $this->waiter, $order, [$hot]);
    expect(DiningOrderItem::find($hot)->status)->toBe('served')->and(count($tickets('KITCHEN')[0]['items']))->toBe(1);

    // A till or tablet signed in by a person cannot use kitchen-screen endpoints, and vice versa.
    app('auth')->forgetGuards();
    $this->withToken('nonsense')->getJson('/api/v1/kds/tickets')->assertUnauthorized();
});

it('voids held items freely but needs a supervisor for items the kitchen has', function () {
    $order = $this->dining->open($this->device, $this->waiter, ['id' => (string) Str::uuid7(), 'kind' => 'tab', 'tab_name' => 'Kwesi']);
    $order = $this->dining->addItems($this->device, $this->waiter, $order, [
        ['id' => (string) Str::uuid7(), 'product_id' => $this->beer->id, 'product_uom_id' => $this->beer->units->first()->id, 'qty' => '1'],
        ['id' => (string) Str::uuid7(), 'product_id' => $this->beer->id, 'product_uom_id' => $this->beer->units->first()->id, 'qty' => '1', 'hold' => true],
    ]);
    [$sent, $held] = $order->items->all();
    $this->dining->void($this->device, $this->waiter, $order, [$held->id], 'Changed mind');
    expect(fn () => $this->dining->void($this->device, $this->waiter, $order, [$sent->id], 'Spilled'))->toThrow(DomainException::class, 'supervisor must approve')
        ->and(fn () => $this->dining->void($this->device, $this->waiter, $order, [$sent->id], 'Spilled', ['username' => 'kojo', 'pin' => '000000']))->toThrow(DomainException::class, 'wrong');
    $order = $this->dining->void($this->device, $this->waiter, $order, [$sent->id], 'Spilled', ['username' => 'kojo', 'pin' => '771204']);
    expect($order->items->pluck('status')->unique()->all())->toBe(['void'])->and($order->items->firstWhere('id', $sent->id)->voided_by_name)->toBe('Kojo')
        ->and($order->label())->toBe('Tab Kwesi');

    // Nothing left to pay: the tab can be closed (as void, nothing was sold).
    expect($this->dining->close($this->device, $this->waiter, $order)->status)->toBe('void');
});

it('splits a bill by items, adds service charge and a tip, uses up recipe ingredients and closes the table', function () {
    $order = $this->dining->open($this->device, $this->waiter, ['id' => (string) Str::uuid7(), 'kind' => 'table', 'dining_table_id' => $this->t1->id]);
    $jollofItem = fn ($opts) => ['id' => (string) Str::uuid7(), 'product_id' => $this->jollof->id, 'product_uom_id' => $this->jollof->units->first()->id, 'qty' => '1', 'modifier_option_ids' => $opts];
    $order = $this->dining->addItems($this->device, $this->waiter, $order, [
        $jollofItem([optionId($this->cooking, 'Hot'), optionId($this->extras, 'Extra cheese')]),
        $jollofItem([optionId($this->cooking, 'Mild')]),
        ['id' => (string) Str::uuid7(), 'product_id' => $this->beer->id, 'product_uom_id' => $this->beer->units->first()->id, 'qty' => '2'],
    ]);
    [$a, $b, $beer] = $order->items->all();
    $bill = ['dining_order_id' => $order->id];

    // Guest 1 pays their jollof — the priced extra must come with it.
    expect(fn () => restSale($this, [[$this->jollof, '1', ['dining_item_id' => $a->id]]], $bill))->toThrow(DomainException::class, 'extras were not charged');
    $first = restSale($this, [[$this->jollof, '1', ['dining_item_id' => $a->id, 'note' => 'Hot, Extra cheese']], [$this->cheese, '1', ['dining_item_id' => $a->id]]], $bill);
    expect($first->total_minor)->toBe(6500)->and($order->fresh()->status)->toBe('open')
        ->and(fn () => restSale($this, [[$this->jollof, '1', ['dining_item_id' => $a->id]], [$this->cheese, '1', ['dining_item_id' => $a->id]]], $bill))
        ->toThrow(DomainException::class, 'already paid');

    // Guest 2 pays the rest with 10 % service charge and a GHS 10 tip.
    $wrong = fn () => restSale($this, [[$this->jollof, '1', ['dining_item_id' => $b->id]], [$this->beer, '2', ['dining_item_id' => $beer->id]]], $bill + ['tip_minor' => 0], service: false);
    $rest = restSale($this, [[$this->jollof, '1', ['dining_item_id' => $b->id]], [$this->beer, '2', ['dining_item_id' => $beer->id]]], $bill, service: true, tip: 1000);
    expect($rest->total_minor)->toBe(9900)->and($rest->tip_minor)->toBe(1000)->and($rest->tip_user_id)->toBe($this->waiter->id)
        ->and($rest->paid_minor)->toBe(10900)->and($rest->lines->firstWhere('is_service_charge', true)->line_total_minor)->toBe(900)
        ->and($order->fresh()->status)->toBe('closed')->and(DiningOrderItem::where('dining_order_id', $order->id)->whereNull('sale_id')->count())->toBe(0)
        ->and(fn () => $wrong())->toThrow(DomainException::class);

    // Ingredients used: 2 jollof = 0.5 kg rice and 2 chicken portions; the dishes themselves hold no stock.
    $onHand = fn (Product $p) => (string) DB::table('stock_levels')->where('warehouse_id', $this->wh->id)->where('product_id', $p->id)->value('on_hand');
    expect($onHand($this->rice))->toBe('9.500')->and($onHand($this->chicken))->toBe('38.000')->and(app(StockLedger::class)->check())->toBe([]);

    // The table is free again; tips are owed to staff in the books, and everything ties out.
    expect($this->dining->open($this->device, $this->waiter, ['id' => (string) Str::uuid7(), 'kind' => 'table', 'dining_table_id' => $this->t1->id])->status)->toBe('open');
    app(AutoPoster::class)->run($this->tenant->id);
    $tips = Account::where('system_key', 'tips_payable')->sole();
    expect((int) DB::table('journal_lines')->where('account_id', $tips->id)->sum('amount_minor'))->toBe(-1000)
        ->and(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('refuses a service charge that differs from the server, and flags offline bill conflicts', function () {
    $order = $this->dining->open($this->device, $this->waiter, ['id' => (string) Str::uuid7(), 'kind' => 'table', 'dining_table_id' => $this->t2->id]);
    $order = $this->dining->addItems($this->device, $this->waiter, $order, [['id' => (string) Str::uuid7(), 'product_id' => $this->beer->id, 'product_uom_id' => $this->beer->units->first()->id, 'qty' => '1']]);
    $beer = $order->items->first();
    app(Settings::class)->set(actorFor($this->owner), 'restaurant.service_charge_bp', 500); // changed after the till priced it at 10 %
    expect(fn () => restSale($this, [[$this->beer, '1', ['dining_item_id' => $beer->id]]], ['dining_order_id' => $order->id], service: true))
        ->toThrow(DomainException::class, 'service charge');
    app(Settings::class)->set(actorFor($this->owner), 'restaurant.service_charge_bp', 1000);

    restSale($this, [[$this->beer, '1', ['dining_item_id' => $beer->id]]], ['dining_order_id' => $order->id]);
    restSale($this, [[$this->beer, '1', ['dining_item_id' => $beer->id]]], ['dining_order_id' => $order->id], offline: true); // a second till, offline
    expect(ReviewItem::where('type', 'dining_conflict')->count())->toBe(1);
});

it('sends counter sales to the kitchen with an order number', function () {
    $sale = restSale($this, [[$this->jollof, '1', ['note' => 'Hot']], [$this->beer, '1'], [$this->cheese, '1']]);
    $counter = DiningOrder::where('kind', 'counter')->sole();
    expect($sale->fresh()->dining_order_id)->toBe($counter->id)->and($counter->status)->toBe('closed')
        ->and($counter->items->pluck('name')->all())->toBe(['Jollof with chicken', 'Club beer'])
        ->and($counter->items->first()->note)->toBe('Hot')->and($counter->items->pluck('status')->unique()->all())->toBe(['fired']);

    // Offline counter sales are not sent to the kitchen later (the till printed the ticket at the time).
    restSale($this, [[$this->beer, '1']], offline: true);
    expect(DiningOrder::where('kind', 'counter')->count())->toBe(1);
});

it('pays tips out through payroll as taxable earnings', function () {
    $hrm = staff($this->owner, 'abena', 4, 'hr_manager');
    $officer = staff($this->owner, 'yaa', 3, 'payroll_officer');
    app(HrService::class)->saveEmployee(actorFor($hrm), new Employee, ['name' => 'Ama Waiter', 'branch_id' => $this->hq->id, 'user_id' => $this->waiter->id,
        'hire_date' => now()->startOfMonth()->subMonth()->toDateString(), 'employment_type' => 'permanent', 'pay_method' => 'cash', 'basic_salary_minor' => 150000]);
    restSale($this, [[$this->beer, '1']], tip: 500);
    restSale($this, [[$this->beer, '1']], tip: 250);
    $run = app(PayrollService::class)->prepare(actorFor($officer), now()->year, now()->month);
    $slip = $run->payslips()->sole();
    expect($slip->tips_minor)->toBe(750)->and($slip->gross_minor)->toBe(150750)
        ->and(collect($slip->lines)->firstWhere('code', 'TIPS')['amount_minor'])->toBe(750);
});

it('keeps restaurant features to branches with the module, and set-up to managers', function () {
    app(ModuleService::class)->setStatus(actorFor($this->owner), $this->hq, 'restaurant', 'disabled');
    expect(fn () => $this->dining->open($this->device, $this->waiter, ['id' => (string) Str::uuid7(), 'kind' => 'tab', 'tab_name' => 'X']))
        ->toThrow(DomainException::class, 'not on at this branch');
    expect(fn () => app(RestaurantSetup::class)->saveTable(actorFor($this->waiter), new DiningTable, ['branch_id' => $this->hq->id, 'code' => 'T9']))->toThrow(AccessDenied::class);
    // Without the module, a counter sale of a dish is a plain sale.
    restSale($this, [[$this->beer, '1']]);
    expect(DiningOrder::count())->toBe(0);
});

it('shows the restaurant screens to managers', function () {
    $order = $this->dining->open($this->device, $this->waiter, ['id' => (string) Str::uuid7(), 'kind' => 'tab', 'tab_name' => 'Kwesi']);
    $this->dining->addItems($this->device, $this->waiter, $order, [['id' => (string) Str::uuid7(), 'product_id' => $this->beer->id, 'product_uom_id' => $this->beer->units->first()->id, 'qty' => '1']]);
    restSale($this, [[$this->beer, '1']], tip: 300);
    $this->actingAs(withMfa($this->owner));
    foreach ([DiningOrderResource::getUrl('index'), DiningOrderResource::getUrl('view', ['record' => $order]), DiningTableResource::getUrl('index'),
        KitchenStationResource::getUrl('index'), ModifierGroupResource::getUrl('index'), RestaurantReport::getUrl(), ProductResource::getUrl('edit', ['record' => $this->jollof])] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get(DiningOrderResource::getUrl('view', ['record' => $order]))->assertSee('Club beer');
    $this->get(RestaurantReport::getUrl())->assertSee('Ama');
    $this->get(ProductResource::getUrl('edit', ['record' => $this->jollof]))->assertSee('Chicken portion');
    $this->flushSession();
    $this->actingAs(withMfa($this->waiter))->get(DiningTableResource::getUrl('index'))->assertForbidden();
});
