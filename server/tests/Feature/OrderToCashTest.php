<?php

use App\Domain\Access\AccessService;
use App\Domain\Customers\CustomerLedger;
use App\Domain\Customers\CustomerService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Sales\CustomerOrderService;
use App\Domain\Sales\DeliveryService;
use App\Domain\Sales\ReceivablesService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Invoice;
use App\Models\PriceList;
use App\Models\ReviewItem;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = Warehouse::where('branch_id', $this->branches['HQ']->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '15.00', carton: ['24', '330.00'], wholesale: '13.00');
    $this->ctn = $this->milo->units->firstWhere('is_base', false);
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->hq, $this->milo, $this->ctn, '40', 'opening', '');
    $this->wholesale = PriceList::where('code', 'WHOLESALE')->first();
    $this->customers = app(CustomerService::class);
    $this->shop = $this->customers->save(actorFor($this->owner), new Customer, ['type' => 'business', 'name' => 'Kofi Provisions', 'phone' => '024 555 1234',
        'tax_no' => 'C0001234567', 'price_list_id' => $this->wholesale->id, 'credit_limit_minor' => 1_000_000, 'payment_terms_days' => 30]); // GHS 10,000 credit, 30 days
    $this->rep = staff($this->owner, 'abena', 2, 'sales_rep', ScopeType::Branch, $this->branches['HQ']->id);
    $this->keeper = staff($this->owner, 'yaw', 2, 'storekeeper', ScopeType::Branch, $this->branches['HQ']->id);
    $this->manager = staff($this->owner, 'kwesi', 4, 'branch_manager', ScopeType::Branch, $this->branches['HQ']->id);
    $this->accountant = staff($this->owner, 'esi', 4, 'accountant');
    $this->orders = app(CustomerOrderService::class);
});

function quote($t, string $cartons, ?Customer $c = null): CustomerOrder
{
    return $t->orders->saveQuotation(actorFor($t->rep), new CustomerOrder, ['customer_id' => ($c ?? $t->shop)->id, 'warehouse_id' => $t->hq->id,
        'customer_ref' => 'KP-PO-88', 'lines' => [['product_uom_id' => $t->ctn->id, 'qty' => $cartons]]]);
}

function balance(Customer $c): int
{
    return (int) DB::table('customer_balances')->where('customer_id', $c->id)->value('balance_minor');
}

it('takes a wholesale order to invoices and a statement (done-when)', function () {
    $q = quote($this, '10'); // wholesale carton price GHS 330 → 3,300.00 incl. tax
    expect($q->quote_number)->toStartWith('QT-')->and($q->total_minor)->toBe(330000)->and($q->price_list_id)->toBe($this->wholesale->id);

    $order = $this->orders->confirm(actorFor($this->rep), $q);
    expect($order->status)->toBe('confirmed')->and($order->number)->toStartWith('SO-');

    // Two deliveries, each raising its invoice (owner decision), due after 30 days.
    $first = app(DeliveryService::class)->deliver(actorFor($this->keeper), $order, [$order->lines[0]->id => '6'], ['delivered_to' => 'Kofi', 'vehicle' => 'GR 1234-24']);
    expect($first['invoice']->total_minor)->toBe(198000)->and($first['invoice']->due_on->toDateString())->toBe(now()->addDays(30)->toDateString())
        ->and($order->fresh()->status)->toBe('partially_delivered')->and(balance($this->shop))->toBe(198000);
    $second = app(DeliveryService::class)->deliver(actorFor($this->keeper), $order->fresh());
    expect($order->fresh()->status)->toBe('delivered')->and(balance($this->shop))->toBe(330000)
        ->and((string) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->value('on_hand'))->toBe('720.000'); // 40 − 10 cartons

    // Payment of GHS 2,500 settles the first invoice and part of the second.
    $pay = app(ReceivablesService::class)->recordPayment(actorFor($this->accountant), $this->shop, $this->branches['HQ'], 'momo', 250000, '51234998877', provider: 'mtn');
    expect($pay->number)->toStartWith('RCT-')
        ->and(app(ReceivablesService::class)->openAmounts($this->shop->id))->toBe([$second['invoice']->id => 80000]);

    // Statement for the month and aged receivables 45 days later.
    $s = app(CustomerLedger::class)->statement($this->shop->id, now()->startOfMonth(), now()->endOfMonth());
    expect($s['opening'])->toBe(0)->and(collect($s['entries'])->pluck('doc_number')->all())->toBe([$first['invoice']->number, $second['invoice']->number, $pay->number])
        ->and($s['closing'])->toBe(80000);
    $this->travel(45)->days();
    expect(app(CustomerLedger::class)->aging()[$this->shop->id])->toMatchArray(['d1_30' => 80000, 'total' => 80000])
        ->and(app(CustomerLedger::class)->check())->toBe([])->and(app(StockLedger::class)->check())->toBe([]);
});

it('puts an order over the credit limit on hold until someone else with a big enough limit releases it', function () {
    $big = $this->orders->confirm(actorFor($this->rep), quote($this, '35')); // 11,550 > 10,000
    expect($big->status)->toBe('on_hold')->and($big->hold_reason)->toContain('over the limit')
        ->and(ReviewItem::where('type', 'credit_hold')->where('status', 'open')->exists())->toBeTrue();

    expect(fn () => app(DeliveryService::class)->deliver(actorFor($this->keeper), $big))->toThrow(DomainException::class, 'credit hold');
    expect(fn () => $this->orders->release(actorFor($this->rep), $big, 'ok'))->toThrow(AccessDenied::class);
    $this->orders->release(actorFor($this->manager), $big->fresh(), 'Payment promised Friday'); // branch manager limit 20,000

    expect($big->fresh()->status)->toBe('confirmed')->and($big->fresh()->released_by_name)->toBe('Kwesi')
        ->and(ReviewItem::where('type', 'credit_hold')->where('status', 'open')->exists())->toBeFalse();
});

it('holds orders for customers with overdue invoices, and confirms cash customers as they are', function () {
    app(DeliveryService::class)->deliver(actorFor($this->keeper), $this->orders->confirm(actorFor($this->rep), quote($this, '1')));
    $this->travel(31)->days();
    expect($this->orders->confirm(actorFor($this->rep), quote($this, '1'))->hold_reason)->toContain('overdue');

    $cash = $this->customers->save(actorFor($this->rep), new Customer, ['name' => 'Walk-in Ama', 'phone' => '0201112222']);
    expect($this->orders->confirm(actorFor($this->rep), quote($this, '1', $cash))->status)->toBe('confirmed');
});

it('credits a paid invoice to the account and uses that credit on the next invoice automatically', function () {
    $order = $this->orders->confirm(actorFor($this->rep), quote($this, '2'));
    $inv = app(DeliveryService::class)->deliver(actorFor($this->keeper), $order)['invoice'];
    app(ReceivablesService::class)->recordPayment(actorFor($this->accountant), $this->shop, $this->branches['HQ'], 'cash', $inv->total_minor);

    $note = app(ReceivablesService::class)->creditNote(actorFor($this->accountant), $inv, 'Damaged cartons', $this->hq);
    expect($note->number)->toStartWith('CRN-')->and($inv->fresh()->status)->toBe('credited')->and(balance($this->shop))->toBe(-66000)
        ->and((string) DB::table('stock_levels')->where('warehouse_id', $this->hq->id)->value('on_hand'))->toBe('960.000'); // goods back

    $next = app(DeliveryService::class)->deliver(actorFor($this->keeper), $this->orders->confirm(actorFor($this->rep), quote($this, '1')))['invoice'];
    expect(app(ReceivablesService::class)->openAmounts($this->shop->id))->toBe([])->and(balance($this->shop))->toBe(-33000)
        ->and(Invoice::find($next->id)->status)->toBe('posted');
});

it('keeps marketing consent with who, when and how; credit is set at head office only', function () {
    $c = $this->customers->save(actorFor($this->rep), $this->shop->fresh(), ['consents' => ['sms' => true, 'email' => false]], 'paper form');
    expect($c->consents['sms'])->toMatchArray(['granted' => true, 'source' => 'paper form', 'by' => 'Abena'])->and($c->hasConsent('email'))->toBeFalse();

    expect(fn () => $this->customers->save(actorFor($this->rep), $c, ['credit_limit_minor' => 9_999_999]))->toThrow(DomainException::class, 'head office');
    expect(fn () => $this->customers->setCredit(actorFor($this->rep), $c, 9_999_999, 30, false))->toThrow(AccessDenied::class);
    config(['erp.node.role' => 'branch']);
    expect(fn () => $this->customers->setCredit(actorFor($this->owner), $c, 9_999_999, 30, false))->toThrow(DomainException::class, 'head office');
});

describe('on the till', function () {
    beforeEach(function () {
        $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
        [$this->device] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->till->branch_id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
        $this->device->forceFill(['status' => 'active'])->save();
        $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $this->till->id);
        $this->supervisor = staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->branches['HQ']->id);
        app(AccessService::class)->setPin(actorFor($this->owner), $this->supervisor, '771204');
        $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
        $this->seq = 0;
        $this->sell = function (string $cartons, array $extra = []) {
            $price = app(PriceResolver::class)->resolve($this->wholesale, $this->milo->id, $this->ctn->id, Qty::toMilli($cartons), now())->amount_minor;
            $t = TaxCalculator::line(Qty::divRound(Qty::toMilli($cartons) * $price, 1000), $this->milo->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->all(), true);

            return app(SaleService::class)->post($this->device, $this->cashier, [
                'id' => (string) Str::uuid7(), 'device_seq' => ++$this->seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $this->wholesale->id,
                'shift_id' => $this->shift->id, 'customer_id' => $this->shop->id,
                'lines' => [['product_id' => $this->milo->id, 'product_uom_id' => $this->ctn->id, 'qty' => $cartons, 'unit_price_minor' => $price, 'line_total_minor' => $t['gross']]],
                'totals' => ['net_minor' => $t['net'], 'tax_minor' => $t['tax'], 'total_minor' => $t['gross']],
                'payments' => [['method' => 'account', 'amount_minor' => $t['gross']]],
            ] + $extra)['sale'];
        };
    });

    it('sells on account within the limit, using the customer’s own price list', function () {
        $sale = ($this->sell)('2');
        expect($sale->customer_id)->toBe($this->shop->id)->and($sale->payments[0]->method)->toBe('account')->and(balance($this->shop))->toBe(66000)
            ->and(app(ReceivablesService::class)->openAmounts($this->shop->id))->toBe([$sale->id => 66000]);
    });

    it('needs a supervisor PIN for a credit sale over the limit, and records who approved it', function () {
        expect(fn () => ($this->sell)('31'))->toThrow(DomainException::class, 'supervisor must approve'); // 10,230 > 10,000
        expect(fn () => ($this->sell)('31', ['credit_approval' => ['username' => 'kojo', 'pin' => '000000']]))->toThrow(DomainException::class, 'wrong');
        // A supervisor's override limit (GHS 1,000) covers the part over the credit limit.
        expect(fn () => ($this->sell)('35', ['credit_approval' => ['username' => 'kojo', 'pin' => '771204']]))->toThrow(DomainException::class, 'cannot approve'); // 1,550 over
        $sale = ($this->sell)('31', ['credit_approval' => ['username' => 'kojo', 'pin' => '771204']]); // 230 over
        expect($sale->payments[0]->approved_by_name)->toBe('Kojo')->and(balance($this->shop))->toBe(1_023_000);
    });
});
