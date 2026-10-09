<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Channels\ChannelAdmin;
use App\Domain\Channels\WebhookIntake;
use App\Domain\Devices\DeviceService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockLedger;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Restaurant\DiningService;
use App\Domain\Restaurant\ReservationService;
use App\Domain\Restaurant\RestaurantSetup;
use App\Domain\Restaurant\SmsRelay;
use App\Domain\Restaurant\WasteService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\ShrinkReport;
use App\Filament\Resources\Reservations\ReservationResource;
use App\Models\Account;
use App\Models\DiningOrder;
use App\Models\DiningTable;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\SalesChannel;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->wh = Warehouse::where('branch_id', $this->hq->id)->first();
    enableModule($this->owner, $this->hq, 'restaurant');
    $o = actorFor($this->owner);
    $this->t1 = app(RestaurantSetup::class)->saveTable($o, new DiningTable, ['branch_id' => $this->hq->id, 'code' => 'T1', 'seats' => 4]);
    $inv = app(InventoryService::class);
    $this->rice = makeProduct($this->owner, 'RICE', 'Rice (kg)', retail: '20.00');
    $this->chicken = makeProduct($this->owner, 'CHK', 'Chicken portion', retail: '30.00');
    foreach ([[$this->rice, '10', '8.00'], [$this->chicken, '40', '12.00']] as [$p, $q, $cost]) {
        $inv->adjust($o, $this->wh, $p, $p->units->first(), $q, 'opening', '', null, $cost);
    }
    $this->jollof = makeProduct($this->owner, 'JOL', 'Jollof with chicken', retail: '60.00');
    app(CatalogService::class)->saveProduct($o, $this->jollof, ['track_stock' => false, 'station' => 'KITCHEN',
        'recipe' => [['product_uom_id' => $this->rice->units->first()->id, 'qty' => '0.25'], ['product_uom_id' => $this->chicken->units->first()->id, 'qty' => '1']]]);
    $this->jollof = $this->jollof->fresh();
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    [$this->device] = app(DeviceService::class)->register($o, ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'T1', 'kind' => 'pos']);
    $this->device->forceFill(['status' => 'active'])->save();
    $this->waiter = staff($this->owner, 'ama', 2, 'waiter', ScopeType::Branch, $this->hq->id);
    $this->supervisor = staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->hq->id);
    app(AccessService::class)->setPin($o, $this->supervisor, '771204');
    $this->shift = app(ShiftService::class)->open($this->device, $this->waiter, 0);
    $this->seq = 0;
    $this->bookings = app(ReservationService::class);
    $this->dining = app(DiningService::class);
    $this->waiterActor = actorFor($this->waiter);
});

/** Pay bill items at the till with the given payments (amounts in pesewas; the rest in cash). */
function payItems($t, DiningOrder $order, array $payments): void
{
    $lines = [];
    $engine = [];
    foreach ($order->items as $i => $item) {
        $p = Product::find($item->product_id);
        $price = app(PriceResolver::class)->resolve($t->retail, $p->id, $item->product_uom_id, Qty::toMilli((string) $item->qty), now())->amount_minor;
        $engine[] = ['key' => (string) $i, 'product_id' => $p->id, 'category_id' => null, 'qty_milli' => Qty::toMilli((string) $item->qty), 'unit_price_minor' => $price,
            'tax' => $p->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null];
        $lines[] = ['product_id' => $p->id, 'product_uom_id' => $item->product_uom_id, 'qty' => (string) $item->qty, 'unit_price_minor' => $price, 'dining_item_id' => $item->id];
    }
    $calc = PromotionEngine::compute(['lines' => $engine, 'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);
    foreach ($calc['lines'] as $i => $r) {
        $lines[$i]['line_total_minor'] = $r['total'];
    }
    $rest = $calc['total'] - array_sum(array_column($payments, 'amount_minor'));
    if ($rest > 0) {
        $payments[] = ['method' => 'cash', 'amount_minor' => $rest, 'tendered_minor' => $rest];
    }
    app(SaleService::class)->post($t->device, $t->waiter, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id, 'lines' => $lines, 'dining_order_id' => $order->id,
        'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']], 'payments' => $payments]);
}

function addJollof($t, DiningOrder $order, int $n = 1): DiningOrder
{
    return $t->dining->addItems($t->device, $t->waiter, $order, array_map(fn () => ['id' => (string) Str::uuid7(), 'product_id' => $t->jollof->id,
        'product_uom_id' => $t->jollof->units->first()->id, 'qty' => '1'], range(1, $n)));
}

it('books a table with a deposit, seats the guests and uses the deposit on their bill', function () {
    $at = now()->addHours(3)->startOfHour();
    $r = $this->bookings->save($this->waiterActor, new Reservation, ['branch_id' => $this->hq->id, 'guest_name' => 'Mensah', 'phone' => '024 111 2222',
        'party_size' => 4, 'starts_at' => $at->toIso8601String(), 'dining_table_id' => $this->t1->id]);
    expect($r->number)->toStartWith('RES-')->and($r->phone)->toBe('0241112222')
        ->and(fn () => $this->bookings->save($this->waiterActor, new Reservation, ['branch_id' => $this->hq->id, 'guest_name' => 'Owusu', 'party_size' => 2,
            'starts_at' => $at->copy()->addHour()->toIso8601String(), 'dining_table_id' => $this->t1->id]))->toThrow(DomainException::class, 'booked for Mensah');

    $r = $this->bookings->takeDeposit($this->device, $this->waiter, $r, 'cash', 5000);
    expect($r->deposit_status)->toBe('held')->and(app(ShiftService::class)->expectedCash($this->shift->fresh()))->toBe(5000);

    $order = $this->bookings->seat($this->device, $this->waiter, $r);
    expect($order->covers)->toBe(4)->and($order->dining_table_id)->toBe($this->t1->id)->and($r->fresh()->status)->toBe('seated');
    $order = addJollof($this, $order, 2); // 120.00

    expect(fn () => payItems($this, $order, [['method' => 'deposit', 'amount_minor' => 6000, 'reference' => $r->number]]))->toThrow(DomainException::class, 'deposit left');
    payItems($this, $order, [['method' => 'deposit', 'amount_minor' => 5000, 'reference' => strtolower($r->number)]]);
    expect($r->fresh()->deposit_status)->toBe('used')->and($r->fresh()->depositLeft())->toBe(0)->and($order->fresh()->status)->toBe('closed')
        ->and(app(ShiftService::class)->expectedCash($this->shift->fresh()))->toBe(12000); // 50 deposit + 70 cash

    app(AutoPoster::class)->run($this->tenant->id);
    $bal = fn (string $key) => (int) DB::table('journal_lines')->where('account_id', Account::where('system_key', $key)->value('id'))->sum('amount_minor');
    expect($bal('customer_deposits'))->toBe(0)->and($bal('cash_till'))->toBe(12000)->and(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('keeps the deposit of a no-show only on a manager’s word, and refunds with a supervisor', function () {
    $book = fn (string $name) => $this->bookings->takeDeposit($this->device, $this->waiter, $this->bookings->save($this->waiterActor, new Reservation, ['branch_id' => $this->hq->id,
        'guest_name' => $name, 'party_size' => 2, 'starts_at' => now()->addDay()->toIso8601String()]), 'momo', 3000, 'mtn', 'MP2410.1234');
    $a = $this->bookings->noShow($this->waiterActor, $book('Asante'));
    expect(fn () => $this->bookings->forfeitDeposit($this->waiterActor, $a, 'No-show'))->toThrow(AccessDenied::class);
    $a = $this->bookings->forfeitDeposit(actorFor($this->owner), $a, 'No-show, did not call');
    expect($a->deposit_status)->toBe('forfeited');

    $b = $this->bookings->cancel($this->waiterActor, $book('Boateng'), 'Plans changed');
    expect(fn () => $this->bookings->refundDeposit($this->device, $this->waiter, $b, 'cash', null))->toThrow(DomainException::class, 'supervisor must approve');
    $b = $this->bookings->refundDeposit($this->device, $this->waiter, $b, 'cash', ['username' => 'kojo', 'pin' => '771204']);
    expect($b->deposit_status)->toBe('refunded')->and(app(ShiftService::class)->expectedCash($this->shift->fresh()))->toBe(-3000); // paid out of the drawer

    app(AutoPoster::class)->run($this->tenant->id);
    expect((int) DB::table('journal_lines')->where('account_id', Account::where('system_key', 'other_income')->value('id'))->sum('amount_minor'))->toBe(-3000)
        ->and(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue();
});

it('texts a confirmation and a reminder through the SMS gateway hook', function () {
    Http::fake(['sms.example.test/*' => Http::response(['ok' => true])]);
    $sms = app(SmsRelay::class);
    $book = fn () => $this->bookings->save($this->waiterActor, new Reservation, ['branch_id' => $this->hq->id, 'guest_name' => 'Mensah', 'phone' => '0241112222',
        'party_size' => 4, 'starts_at' => now()->addHours(2)->toIso8601String()]);
    $book();
    $sms->run();
    Http::assertNothingSent(); // no gateway configured: nothing goes out

    app(Settings::class)->set(actorFor($this->owner), 'restaurant.sms_webhook_url', 'https://sms.example.test/send');
    $r = $book();
    $sms->run();
    Http::assertSent(fn ($req) => $req['to'] === '0241112222' && str_contains($req['message'], 'table for 4 booked') && $req['reference'] === $r->number);

    Carbon::setTestNow(now()->addMinutes(70)); // booked over an hour ago, starting within the reminder window
    expect($sms->queueReminders())->toBe(2)->and($sms->queueReminders())->toBe(0);
    $sms->run();
    Http::assertSent(fn ($req) => str_contains($req['message'], 'reminder of your table for 4'));
    Carbon::setTestNow();
});

it('takes delivery-app orders keyed in at the till or by webhook, and records them as due from the platform', function () {
    $o = $this->dining->open($this->device, $this->waiter, ['id' => (string) Str::uuid7(), 'kind' => 'delivery', 'platform' => 'bolt_food', 'external_ref' => 'bf-1001']);
    expect($o->label())->toBe('Bolt Food #BF-1001')
        ->and(fn () => $this->dining->open($this->device, $this->waiter, ['id' => (string) Str::uuid7(), 'kind' => 'delivery', 'platform' => 'bolt_food', 'external_ref' => 'BF-1001']))
        ->toThrow(DomainException::class, 'already in');
    $o = addJollof($this, $o);
    payItems($this, $o, [['method' => 'aggregator', 'provider' => 'bolt_food', 'amount_minor' => 6000, 'reference' => 'BF-1001']]);
    app(AutoPoster::class)->run($this->tenant->id);
    expect((int) DB::table('journal_lines')->where('account_id', Account::where('system_key', 'aggregator_receivable')->value('id'))->sum('amount_minor'))->toBe(6000);

    // Webhook (Glovo): signed like the REST channel; the order goes straight to the kitchen.
    $admin = app(ChannelAdmin::class);
    $ch = $admin->save(actorFor($this->owner), new SalesChannel, ['type' => 'glovo', 'name' => 'Glovo Osu', 'branch_id' => $this->hq->id, 'warehouse_id' => $this->wh->id,
        'price_list_id' => $this->retail->id], ['webhook_secret' => 'glovo-test-secret']);
    $admin->activate(actorFor($this->owner), $ch);
    $send = function (array $order) use ($ch) {
        $raw = json_encode(['topic' => 'order.created', 'order' => $order]);
        $ts = (string) time();

        return $this->call('POST', "/api/v1/channels/{$ch->id}/webhooks", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'HTTP_X_WEBHOOK_ID' => (string) Str::uuid7(), 'HTTP_X_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $ts.'.'.$raw, 'glovo-test-secret')], $raw);
    };
    $send(['id' => 'g-77', 'number' => 'GL-77', 'customer' => ['name' => 'Kofi', 'phone' => '0201234567'], 'lines' => [['sku' => 'JOL', 'qty' => '2', 'note' => 'no pepper']]])->assertOk();
    $send(['id' => 'g-78', 'lines' => [['sku' => 'NOPE', 'qty' => '1']]])->assertOk();
    app(WebhookIntake::class)->process($ch->fresh());
    $g = DiningOrder::where('platform', 'glovo')->sole();
    expect($g->label())->toBe('Glovo #GL-77')->and($g->note)->toContain('Kofi')->and($g->items->sole()->status)->toBe('fired')
        ->and($g->items->sole()->note)->toBe('no pepper')
        ->and(DB::table('webhook_inbox')->where('status', 'failed')->count())->toBe(1);
});

it('logs kitchen waste from the till and the kitchen screen, dishes as their ingredients, with a manager above the limit', function () {
    $waste = app(WasteService::class);
    $r = $waste->record($this->device, $this->waiter, [['product_id' => $this->jollof->id, 'product_uom_id' => $this->jollof->units->first()->id, 'qty' => '1']], 'Dropped');
    // 0.25 kg rice at 8.00 + 1 chicken at 12.00 = 14.00
    expect($r['value_minor'])->toBe(1400)->and($r['approved_by'])->toBeNull();
    $onHand = fn (Product $p) => (string) DB::table('stock_levels')->where('warehouse_id', $this->wh->id)->where('product_id', $p->id)->value('on_hand');
    expect($onHand($this->rice))->toBe('9.750')->and($onHand($this->chicken))->toBe('39.000');

    $big = [['product_id' => $this->chicken->id, 'product_uom_id' => $this->chicken->units->first()->id, 'qty' => '5']]; // 60.00 > 50.00
    expect(fn () => $waste->record($this->device, $this->waiter, $big, 'Fridge failed'))->toThrow(DomainException::class, 'manager');
    expect($waste->record($this->device, $this->waiter, $big, 'Fridge failed', ['username' => 'kojo', 'pin' => '771204'])['approved_by'])->toBe('Kojo');

    // Kitchen screen: the cook gives username and PIN.
    $cook = staff($this->owner, 'yaw', 1, 'kitchen_staff', ScopeType::Branch, $this->hq->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $cook, '246810');
    [$kds, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->hq->id, 'name' => 'Pass', 'kind' => 'kds']);
    $token = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->json('token');
    expect(collect($this->withToken($token)->getJson('/api/v1/kds/products')->assertOk()->json('products'))->pluck('sku')->all())->toContain('JOL', 'RICE');
    $this->withToken($token)->postJson('/api/v1/kds/waste', ['username' => 'yaw', 'pin' => '000000', 'reason' => 'Burnt',
        'items' => [['product_id' => $this->rice->id, 'product_uom_id' => $this->rice->units->first()->id, 'qty' => '0.5']]])->assertUnauthorized(); // wrong PIN (counts towards the lockout)
    $this->withToken($token)->postJson('/api/v1/kds/waste', ['username' => 'yaw', 'pin' => '246810', 'reason' => 'Burnt',
        'items' => [['product_id' => $this->rice->id, 'product_uom_id' => $this->rice->units->first()->id, 'qty' => '0.5']]])->assertCreated()->assertJson(['value_minor' => 400]);

    $page = new ShrinkReport;
    $page->from = now()->subDay()->toDateString();
    $page->to = now()->toDateString();
    $this->actingAs($this->owner, 'web'); // the API calls above switched the default guard to the device token
    expect((int) $page->byReason()->firstWhere('reason', 'Kitchen waste')->value)->toBe(1400 + 6000 + 400)
        ->and(app(StockLedger::class)->check())->toBe([]);
});

it('shows bookings in the back office and on the floor', function () {
    $r = $this->bookings->save($this->waiterActor, new Reservation, ['branch_id' => $this->hq->id, 'guest_name' => 'Mensah', 'party_size' => 4,
        'starts_at' => now()->addHour()->toIso8601String(), 'dining_table_id' => $this->t1->id]);
    $this->actingAs(withMfa($this->owner));
    $this->get(ReservationResource::getUrl('index'))->assertOk()->assertSee('Mensah');
    $this->flushSession();
    app('auth')->forgetGuards();
    app(AccessService::class)->setPin(actorFor($this->owner), $this->waiter, '135790');
    [, $code] = app(DeviceService::class)->register(actorFor($this->owner), ['branch_id' => $this->hq->id, 'till_id' => $this->till->id, 'name' => 'Tablet', 'kind' => 'pos']);
    $device = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->json('token');
    app('auth')->forgetGuards();
    $staff = $this->withToken($device)->postJson('/api/v1/pos/login', ['username' => 'ama', 'pin' => '135790'])->assertOk()->json('token');
    app('auth')->forgetGuards();
    expect($this->withToken($staff)->getJson('/api/v1/pos/restaurant/floor')->assertOk()->json('reservations.0.number'))->toBe($r->number);
});
