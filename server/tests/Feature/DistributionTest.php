<?php

use App\Domain\Catalog\CatalogService;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Distribution\DistributionReports;
use App\Domain\Distribution\RouteService;
use App\Domain\Distribution\VanService;
use App\Domain\Distribution\VanSettlementService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Hr\HrService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\DistributionReport;
use App\Filament\Resources\RepTargets\RepTargetResource;
use App\Filament\Resources\SalesRoutes\SalesRouteResource;
use App\Filament\Resources\Vans\VanResource;
use App\Filament\Resources\VanSettlements\VanSettlementResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Commission;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Employee;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalesRoute;
use App\Models\StockLevel;
use App\Models\Till;
use App\Models\Visit;
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
    enableModule($this->owner, $this->hq, 'distribution');
    $this->o = actorFor($this->owner);
    $this->depot = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->rep = staff($this->owner, 'kojo', 2, 'van_sales_rep', ScopeType::Branch, $this->hq->id);
    $this->van = app(VanService::class)->create($this->o, $this->hq, ['code' => 'V01', 'name' => 'Kumasi van 1', 'vehicle_reg' => 'gr 1234-24',
        'rep_user_id' => $this->rep->id, 'home_warehouse_id' => $this->depot->id]);
    $this->till = Till::where('warehouse_id', $this->van->id)->sole();
    [$this->device] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'Kojo phone', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->shift = app(ShiftService::class)->open($this->device, $this->rep, 0);
    $this->seq = 0;

    $this->malt = makeProduct($this->owner, 'MALT-33', 'Malt 330 ml', retail: '10.00');
    $this->crate = makeProduct($this->owner, 'CRATE-24', 'Malt crate (empty)', retail: '24.00');
    $catalog = app(CatalogService::class);
    $catalog->saveProduct($this->o, $this->crate, ['is_container' => true]);
    $catalog->saveProduct($this->o, $this->malt, ['deposit_product_id' => $this->crate->id, 'deposit_per_unit' => '1']);
    $this->malt = $this->malt->fresh();
    $this->crate = $this->crate->fresh();
    $inv = app(InventoryService::class);
    $inv->adjust($this->o, $this->depot, $this->malt, $this->malt->units->first(), '200', 'opening', '', null, '7.00');
    $inv->adjust($this->o, $this->depot, $this->crate, $this->crate->units->first(), '50', 'opening', '', null, '20.00');
    $this->ama = app(CustomerService::class)->save($this->o, new Customer, ['name' => 'Ama Provisions', 'phone' => '0241112222', 'credit_limit_minor' => 50000, 'payment_terms_days' => 14]);
});

function vanQty(Warehouse $wh, Product $p): string
{
    return (string) (StockLevel::where('warehouse_id', $wh->id)->where('product_id', $p->id)->value('on_hand') ?? '0.000');
}

/** A sale on the van's till; items are [product, qty]; the payment defaults to cash for the total. */
function vanSale($t, array $items, ?array $payments = null, array $extra = []): Sale
{
    $lines = [];
    $engine = [];
    foreach ($items as $i => [$p, $qty]) {
        $unit = $p->units->first();
        $price = app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, abs(Qty::toMilli($qty)), now())->amount_minor;
        $engine[] = ['key' => (string) $i, 'product_id' => $p->is_container ? 'container:'.$p->id : $p->id, 'category_id' => null, 'qty_milli' => Qty::toMilli($qty),
            'unit_price_minor' => $price, 'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price];
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }

    return app(SaleService::class)->post($t->device, $t->rep, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'lines' => $lines, 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => $payments ?? [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]] + $extra)['sale'];
}

it('sets up a van as a warehouse with its own till, loads it and sells from it with deposits and empties', function () {
    expect($this->van->is_van)->toBeTrue()->and($this->van->vehicle_reg)->toBe('GR 1234-24')->and($this->till->code)->toBe('V01')
        ->and(fn () => app(VanService::class)->create(actorFor($this->rep), $this->hq, ['code' => 'V02', 'name' => 'X', 'home_warehouse_id' => $this->depot->id]))->toThrow(AccessDenied::class);

    app(VanService::class)->load($this->o, $this->van, [['product_uom_id' => $this->malt->units->first()->id, 'qty' => '48'], ['product_uom_id' => $this->crate->units->first()->id, 'qty' => '10']]);
    expect(vanQty($this->van, $this->malt))->toBe('48.000')->and(vanQty($this->depot, $this->malt))->toBe('152.000');

    // 24 bottles with 24 bottle-deposits, and 4 empties handed back.
    $sale = vanSale($this, [[$this->malt, '24'], [$this->crate, '24'], [$this->crate, '-4']]);
    expect($sale->total_minor)->toBe(24000 + 57600 - 9600)->and(vanQty($this->van, $this->crate))->toBe('-10.000'); // 10 − 24 + 4

    // Only empties back: the deposit is paid out in cash.
    $back = vanSale($this, [[$this->crate, '-2']], [['method' => 'cash', 'amount_minor' => -4800, 'tendered_minor' => -4800]]);
    expect($back->total_minor)->toBe(-4800)->and(fn () => vanSale($this, [[$this->malt, '-1']]))->toThrow(DomainException::class, 'above zero');
    app(AutoPoster::class)->run($this->tenant->id);
    expect(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('books container deposits as money owed to customers when the setting is on', function () {
    $gl = fn (string $key) => (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
        ->where('accounts.system_key', $key)->sum('journal_lines.amount_minor');
    app(VanService::class)->load($this->o, $this->van, [['product_uom_id' => $this->malt->units->first()->id, 'qty' => '48'], ['product_uom_id' => $this->crate->units->first()->id, 'qty' => '30']]);
    app(Settings::class)->set($this->o, 'distribution.deposits_as_liability', true);

    $sale = vanSale($this, [[$this->malt, '24'], [$this->crate, '24'], [$this->crate, '-4']]);
    $deposit = (int) $sale->lines()->where('product_id', $this->crate->id)->sum('net_minor');
    $malt = (int) $sale->lines()->where('product_id', $this->malt->id)->sum('net_minor');
    vanSale($this, [[$this->crate, '-2']], [['method' => 'cash', 'amount_minor' => -4800, 'tendered_minor' => -4800]]);
    $back = (int) Sale::latest('device_seq')->first()->lines()->sum('net_minor');
    app(AutoPoster::class)->run($this->tenant->id);

    expect($gl('container_deposits'))->toBe(-($deposit + $back))->and($gl('sales_pos'))->toBe(-$malt)
        ->and($gl('containers_out'))->toBe(18 * 2000)->and($gl('cogs'))->toBe(24 * 700)
        ->and(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('records visits with GPS and takes orders on the road, credit-checked, once', function () {
    $route = app(RouteService::class)->save($this->o, new SalesRoute, ['code' => 'kms-n', 'name' => 'Kumasi North', 'branch_id' => $this->hq->id,
        'van_warehouse_id' => $this->van->id, 'rep_user_id' => $this->rep->id, 'days' => [now()->isoWeekday()], 'customer_ids' => [$this->ama->id]]);
    $today = RouteService::today($this->till);
    expect($route->code)->toBe('KMS-N')->and($today['stops'])->toHaveCount(1)->and($today['stops'][0]['customer_id'])->toBe($this->ama->id)
        ->and(DistributionReports::missedVisits(now()->toDateString(), 'UTC'))->toHaveCount(1);

    $id = (string) Str::uuid7();
    $visit = ['id' => $id, 'customer_id' => $this->ama->id, 'route_id' => $route->id, 'outcome' => 'no_order', 'note' => 'Still has stock', 'lat' => 6.6885, 'lng' => -1.6244,
        'accuracy_m' => 12, 'occurred_at' => now()->toIso8601String()];
    expect(app(RouteService::class)->visit($this->device, $this->rep, $visit)['status'])->toBe('accepted')
        ->and(app(RouteService::class)->visit($this->device, $this->rep, $visit)['status'])->toBe('duplicate')
        ->and((float) Visit::sole()->lat)->toBe(6.6885)->and(DistributionReports::missedVisits(now()->toDateString(), 'UTC'))->toHaveCount(0);

    $orderId = (string) Str::uuid7();
    $order = ['id' => $orderId, 'customer_id' => $this->ama->id, 'lines' => [['product_uom_id' => $this->malt->units->first()->id, 'qty' => '24']]];
    $r = app(RouteService::class)->takeOrder($this->device, $this->rep, $order);
    expect($r['status'])->toBe('accepted')->and($r['order']->status)->toBe('confirmed')->and($r['order']->warehouse_id)->toBe($this->depot->id)
        ->and(app(RouteService::class)->takeOrder($this->device, $this->rep, $order)['status'])->toBe('duplicate');
    // Over the credit limit: the order waits on credit hold for the office.
    $big = app(RouteService::class)->takeOrder($this->device, $this->rep, ['id' => (string) Str::uuid7(), 'customer_id' => $this->ama->id,
        'lines' => [['product_uom_id' => $this->malt->units->first()->id, 'qty' => '100']]]);
    expect($big['order']->status)->toBe('on_hold')->and(CustomerOrder::count())->toBe(2);
});

it('pays the rep commission on van sales and reports them against target', function () {
    app(HrService::class)->saveEmployee($this->o, new Employee, ['name' => 'Kojo Rep', 'branch_id' => $this->hq->id, 'hire_date' => now()->subYear()->toDateString(),
        'employment_type' => 'permanent', 'pay_method' => 'cash', 'commission_bp' => 300, 'user_id' => $this->rep->id]);
    app(RouteService::class)->setTarget($this->o, ['user_id' => $this->rep->id, 'year' => now()->year, 'month' => now()->month, 'target' => '1000.00', 'visit_target' => 40]);
    app(VanService::class)->load($this->o, $this->van, [['product_uom_id' => $this->malt->units->first()->id, 'qty' => '48']]);
    $sale = vanSale($this, [[$this->malt, '20']], [['method' => 'account', 'amount_minor' => 20000, 'tendered_minor' => 20000]], ['customer_id' => $this->ama->id]);
    expect(Commission::sole()->amount_minor)->toBe(intdiv($sale->net_minor * 300 + 5000, 10000));

    [$row] = DistributionReports::reps(now()->startOfMonth()->toDateString(), now()->toDateString(), 'UTC');
    expect($row['name'])->toBe($this->rep->name)->and($row['sales_minor'])->toBe($sale->net_minor)->and($row['target_minor'])->toBe(100000)
        ->and($row['achieved_bp'])->toBe(intdiv($sale->net_minor * 10000, 100000));
});

it('settles the van at the end of the day: shortages booked, unsold stock unloaded, approved by someone else', function () {
    app(VanService::class)->load($this->o, $this->van, [['product_uom_id' => $this->malt->units->first()->id, 'qty' => '48']]);
    vanSale($this, [[$this->malt, '10']]);
    app(ShiftService::class)->close($this->device, $this->rep, 10000, null);
    $keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->hq->id);
    $svc = app(VanSettlementService::class);
    $s = $svc->start(actorFor($keeper), $this->van);
    expect($s->number)->toStartWith('VS-')->and($s->lines)->toHaveCount(1)->and((string) $s->lines->first()->expected_qty)->toBe('38.000')
        ->and($s->sales_minor)->toBe(10000)->and($s->cash_counted_minor)->toBe(10000)
        ->and(fn () => $svc->start(actorFor($keeper), $this->van))->toThrow(DomainException::class, 'already open');

    // 37 found (one bottle short); 30 go back to the depot, 7 stay on the van.
    $svc->count(actorFor($keeper), $s, [$this->malt->id => ['counted' => '37', 'unload' => '30']]);
    $svc->submit(actorFor($keeper), $s->fresh());
    expect(fn () => $svc->post(actorFor($keeper), $s->fresh()))->toThrow(AccessDenied::class);
    $sup = staff($this->owner, 'efua', 3, 'supervisor', ScopeType::Branch, $this->hq->id);
    $posted = $svc->post(actorFor($sup), $s->fresh());
    expect($posted->status)->toBe('posted')->and(vanQty($this->van, $this->malt))->toBe('7.000')->and(vanQty($this->depot, $this->malt))->toBe('182.000')
        ->and($posted->stock_variance_minor)->toBe(-700);
});

it('shows the distribution screens', function () {
    $route = app(RouteService::class)->save($this->o, new SalesRoute, ['code' => 'R1', 'name' => 'Route one', 'branch_id' => $this->hq->id, 'van_warehouse_id' => $this->van->id,
        'days' => [1, 3, 5], 'customer_ids' => [$this->ama->id]]);
    $s = app(VanSettlementService::class)->start($this->o, $this->van);
    $this->actingAs(withMfa($this->owner));
    foreach ([VanResource::getUrl('index'), VanSettlementResource::getUrl('index'), VanSettlementResource::getUrl('view', ['record' => $s]), SalesRouteResource::getUrl('index'),
        VisitResource::getUrl('index'), RepTargetResource::getUrl('index'), DistributionReport::getUrl()] as $url) {
        $this->get($url)->assertOk();
    }
    $this->get(VanResource::getUrl('index'))->assertSee('Kumasi van 1')->assertSee('GR 1234-24');
    $this->get(SalesRouteResource::getUrl('index'))->assertSee('Route one')->assertSee('Mon Wed Fri');
});
