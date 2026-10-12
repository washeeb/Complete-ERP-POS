<?php

use App\Domain\Access\AccessService;
use App\Domain\Customers\CustomerService;
use App\Domain\Customers\Loyalty;
use App\Domain\Customers\StoredValues;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pos\ReturnService;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Pricing\PromotionService;
use App\Domain\Support\DomainException;
use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Promotion;
use App\Models\ReviewItem;
use App\Models\Sale;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->wh = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    $this->bread = makeProduct($this->owner, 'BREAD', 'Bread', retail: '25.00', tax: 'EXEMPT');
    foreach ([$this->milo, $this->bread] as $p) {
        app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $p, $p->units->first(), '50', 'opening', '');
    }
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    [$this->device] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->till->branch_id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->supervisor = staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->supervisor, '771204');
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 10000);
    $this->ama = app(CustomerService::class)->save(actorFor($this->owner), new Customer, ['name' => 'Ama Owusu', 'phone' => '0241112222']);
    $this->seq = 0;
    $this->promos = app(PromotionService::class);
});

/**
 * A sale as an up-to-date till would send it: the same engine with the promotions running now.
 *
 * @param  list<array{0: mixed, 1: string, 2?: ?array}>  $items  [product, qty, manual]
 */
function till($t, array $items, ?array $payWith = null, array $extra = [], ?array $promotions = null): array
{
    $lines = [];
    $engine = [];
    foreach ($items as $i => $it) {
        [$p, $qty] = $it;
        $unit = $p->units->first();
        $price = app(PriceResolver::class)->resolve($t->retail, $p->id, $unit->id, Qty::toMilli($qty), now())->amount_minor;
        $engine[] = ['key' => (string) $i, 'product_id' => $p->id, 'category_id' => $p->category_id, 'qty_milli' => Qty::toMilli($qty), 'unit_price_minor' => $price,
            'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => $it[2] ?? null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $unit->id, 'qty' => $qty, 'unit_price_minor' => $price] + (isset($it[2]) ? ['manual' => $it[2]] : []);
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => $extra['coupon_code'] ?? null, 'sale_discount_bp' => $extra['sale_discount_bp'] ?? 0,
        'promotions' => $promotions ?? PromotionService::active($t->branches['HQ']->id, $t->retail->id, now())]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }

    return ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id,
        'lines' => $lines, 'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => $payWith ?? [['method' => 'cash', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']]]] + $extra;
}

function sell($t, array $payload): Sale
{
    return app(SaleService::class)->post($t->device, $t->cashier, $payload)['sale'];
}

it('applies the best promotion per item on the server exactly as the till does, and refuses a till that missed it', function () {
    $this->promos->save(actorFor($this->owner), new Promotion, ['name' => 'Milo 10%', 'type' => 'percent', 'product_ids' => [$this->milo->id], 'value_bp' => 1000]);
    $this->promos->save(actorFor($this->owner), new Promotion, ['name' => 'Bread 3 for 2', 'type' => 'buy_x_get_y', 'product_ids' => [$this->bread->id], 'buy_qty' => 2, 'get_qty' => 1]);

    $sale = sell($this, till($this, [[$this->milo, '2'], [$this->bread, '3']]));
    expect($sale->total_minor)->toBe(10440 + 5000)->and($sale->discount_minor)->toBe(1160 + 2500)
        ->and($sale->lines[0]->promotions[0]['name'])->toBe('Milo 10%')->and($sale->lines[1]->discount_minor)->toBe(2500);

    $stale = till($this, [[$this->milo, '1']], promotions: []); // a till that has not heard about the promotion
    expect(fn () => sell($this, $stale))->toThrow(DomainException::class, 'promotions changed');
});

it('runs a happy hour only in its window (branch time)', function () {
    $this->promos->save(actorFor($this->owner), new Promotion, ['name' => 'Bread happy hour', 'type' => 'percent', 'product_ids' => [$this->bread->id], 'value_bp' => 2000,
        'time_from' => '16:00', 'time_to' => '18:00']);
    $this->travelTo(now()->setTime(17, 0));
    expect(PromotionService::active($this->branches['HQ']->id, $this->retail->id, now()))->toHaveCount(1);
    $this->travelTo(now()->setTime(18, 0));
    expect(PromotionService::active($this->branches['HQ']->id, $this->retail->id, now()))->toHaveCount(0);
});

it('takes a coupon, and refuses an invalid one online', function () {
    $this->promos->save(actorFor($this->owner), new Promotion, ['name' => 'Back to school', 'type' => 'coupon', 'coupon_code' => 'school5', 'value_minor' => 500, 'min_spend_minor' => 5000]);
    $sale = sell($this, till($this, [[$this->milo, '1']], extra: ['coupon_code' => 'SCHOOL5']));
    expect($sale->total_minor)->toBe(5300)->and($sale->coupon_code)->toBe('SCHOOL5');

    expect(fn () => sell($this, till($this, [[$this->bread, '1']], extra: ['coupon_code' => 'SCHOOL5'])))->toThrow(DomainException::class, 'minimum spend');
});

it('keeps manual discounts within limits: cashier 0 %, supervisor 10 % by PIN', function () {
    expect(fn () => sell($this, till($this, [[$this->milo, '1', ['bp' => 500]]])))->toThrow(DomainException::class, 'supervisor must approve a 5.00 % discount');

    $sale = sell($this, till($this, [[$this->milo, '1', ['bp' => 500]]], extra: ['discount_approval' => ['username' => 'kojo', 'pin' => '771204']]));
    expect($sale->total_minor)->toBe(5510)->and($sale->discount_approved_by_name)->toBe('Kojo');

    expect(fn () => sell($this, till($this, [[$this->milo, '1', ['price_minor' => 5000]]], extra: ['discount_approval' => ['username' => 'kojo', 'pin' => '771204']])))
        ->toThrow(DomainException::class, 'cannot approve'); // 13.8 % > 10 %
});

it('earns and spends loyalty points (1 per GHS 1; 100 = GHS 1; from 500)', function () {
    $sale = sell($this, till($this, [[$this->milo, '2']], extra: ['customer_id' => $this->ama->id]));
    expect($sale->points_earned)->toBe(116)->and(app(Loyalty::class)->balance($this->ama->id))->toBe(116);

    expect(fn () => sell($this, till($this, [[$this->bread, '1']], [['method' => 'points', 'amount_minor' => 100], ['method' => 'cash', 'amount_minor' => 2400]],
        ['customer_id' => $this->ama->id])))->toThrow(DomainException::class, 'Points can be used from 500');

    foreach (range(1, 4) as $i) {
        sell($this, till($this, [[$this->milo, '2']], extra: ['customer_id' => $this->ama->id]));
    }
    sell($this, till($this, [[$this->bread, '1']], [['method' => 'points', 'amount_minor' => 500], ['method' => 'cash', 'amount_minor' => 2000]], ['customer_id' => $this->ama->id]));
    expect(app(Loyalty::class)->balance($this->ama->id))->toBe(580 - 500 + 20)->and(app(Loyalty::class)->check())->toBe([]);
});

it('sells a gift card in the back office, redeems it at the till, and never overdraws it online', function () {
    $card = app(StoredValues::class)->sellCard(actorFor($this->owner), 10000, 'cash', 'Front desk');
    expect($card->code)->toStartWith('GC')->and($card->balanceMinor())->toBe(10000);

    sell($this, till($this, [[$this->milo, '1']], [['method' => 'gift_card', 'amount_minor' => 5800, 'reference' => strtolower($card->code)]]));
    expect($card->balanceMinor())->toBe(4200);
    expect(fn () => sell($this, till($this, [[$this->milo, '1']], [['method' => 'gift_card', 'amount_minor' => 5800, 'reference' => $card->code]])))
        ->toThrow(DomainException::class, 'holds only 42.00');
});

it('takes goods back against the receipt, refunding the original tenders, reversing points, and back into stock', function () {
    $sale = sell($this, till($this, [[$this->milo, '2']], extra: ['customer_id' => $this->ama->id])); // 116.00 cash, 116 points
    $returns = app(ReturnService::class);
    $info = $returns->returnable($sale->fresh('lines', 'payments'));
    expect($info['within_period'])->toBeTrue()->and($info['lines'][0]['returnable_qty'])->toBe('2.000');

    $ret = $returns->post($this->device, $this->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$this->seq, 'occurred_at' => now()->toIso8601String(),
        'shift_id' => $this->shift->id, 'original_sale_id' => $sale->id, 'reason' => 'Dented tin',
        'lines' => [['original_line_id' => $sale->lines[0]->id, 'qty' => '1']], 'refunds' => [['method' => 'cash', 'amount_minor' => 5800]]])['sale'];

    expect($ret->kind)->toBe('return')->and($ret->total_minor)->toBe(-5800)->and(app(Loyalty::class)->balance($this->ama->id))->toBe(58)
        ->and((string) DB::table('stock_levels')->where('warehouse_id', $this->wh->id)->where('product_id', $this->milo->id)->value('on_hand'))->toBe('49.000')
        ->and(app(ShiftService::class)->expectedCash($this->shift->fresh()))->toBe(10000 + 11600 - 5800)
        ->and(app(StockLedger::class)->check())->toBe([]);
    expect(fn () => $returns->post($this->device, $this->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$this->seq, 'occurred_at' => now()->toIso8601String(),
        'shift_id' => $this->shift->id, 'original_sale_id' => $sale->id, 'reason' => 'again', 'lines' => [['original_line_id' => $sale->lines[0]->id, 'qty' => '2']],
        'refunds' => [['method' => 'cash', 'amount_minor' => 11600]]]))->toThrow(DomainException::class, 'only 1');
});

it('gives store credit (supervisor PIN) for a return without a receipt, usable at the till', function () {
    $payload = ['id' => (string) Str::uuid7(), 'device_seq' => ++$this->seq, 'occurred_at' => now()->toIso8601String(), 'shift_id' => $this->shift->id,
        'reason' => 'Gift, no receipt', 'lines' => [['product_uom_id' => $this->bread->units->first()->id, 'qty' => '2']]];
    expect(fn () => app(ReturnService::class)->post($this->device, $this->cashier, $payload))->toThrow(DomainException::class, 'supervisor must approve');

    $r = app(ReturnService::class)->post($this->device, $this->cashier, $payload + ['approval' => ['username' => 'kojo', 'pin' => '771204']]);
    expect($r['store_credit_code'])->toStartWith('SC')->and(app(StoredValues::class)->find($r['store_credit_code'])->balanceMinor())->toBe(5000)
        ->and(ReviewItem::where('type', 'return_no_receipt')->exists())->toBeTrue();

    sell($this, till($this, [[$this->bread, '2']], [['method' => 'store_credit', 'amount_minor' => 5000, 'reference' => $r['store_credit_code']]]));
    expect(app(StoredValues::class)->find($r['store_credit_code'])->balanceMinor())->toBe(0);
});

it('expires points and unused card balances after 12 months (at head office)', function () {
    sell($this, till($this, [[$this->milo, '1']], extra: ['customer_id' => $this->ama->id]));
    $card = app(StoredValues::class)->sellCard(actorFor($this->owner), 2000, 'cash', null);
    $this->travel(12)->months();
    $this->travel(2)->days();
    Artisan::call('erp:expire-balances');

    expect(app(Loyalty::class)->balance($this->ama->id))->toBe(0)->and($card->balanceMinor())->toBe(0)
        ->and(app(StoredValues::class)->check())->toBe([])->and(app(Loyalty::class)->check())->toBe([]);
});
