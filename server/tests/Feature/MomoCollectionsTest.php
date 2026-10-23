<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Payments\MomoCollections;
use App\Domain\Pos\SaleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\PromotionEngine;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Resources\MomoCharges\Pages\ListMomoCharges;
use App\Models\Device;
use App\Models\MomoCharge;
use App\Models\PriceList;
use App\Models\SalePayment;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->o = actorFor($this->owner);
    $wh = Warehouse::where('branch_id', $this->hq->id)->first();
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '50.00');
    app(InventoryService::class)->adjust($this->o, $wh, $this->milo, $this->milo->units->first(), '50', 'opening', '', null, '40.00');
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $till = Till::where('branch_id', $this->hq->id)->first();
    [$device, $code] = app(DeviceService::class)->register($this->o, ['branch_id' => $this->hq->id, 'till_id' => $till->id, 'name' => 'T1', 'kind' => 'pos']);
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $this->device = Device::find($device->id);
    $this->cashier = staff($this->owner, 'efua', 2, 'teller', ScopeType::Till, $till->id);
    app(AccessService::class)->setPin($this->o, $this->cashier, '4826');
    $this->token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'efua', 'pin' => '4826'])->json('token');
    app('auth')->forgetGuards();
    $this->shift = app(ShiftService::class)->open($this->device, $this->cashier, 0);
    $this->seq = 0;
    $this->momo = app(MomoCollections::class);
});

/** A sale of one Milo (50.00) paid with the given payment. */
function momoSale($t, array $payment): array
{
    $unit = $t->milo->units->first();
    $price = app(PriceResolver::class)->resolve($t->retail, $t->milo->id, $unit->id, Qty::toMilli('1'), now())->amount_minor;
    $calc = PromotionEngine::compute(['lines' => [['key' => '0', 'product_id' => $t->milo->id, 'category_id' => null, 'qty_milli' => Qty::toMilli('1'), 'unit_price_minor' => $price,
        'tax' => $t->milo->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->values()->all(), 'manual' => null]],
        'inclusive' => true, 'coupon' => null, 'sale_discount_bp' => 0, 'promotions' => []]);

    return app(SaleService::class)->post($t->device, $t->cashier, ['id' => (string) Str::uuid7(), 'device_seq' => ++$t->seq, 'occurred_at' => now()->toIso8601String(),
        'price_list_id' => $t->retail->id, 'shift_id' => $t->shift->id,
        'lines' => [['product_id' => $t->milo->id, 'product_uom_id' => $unit->id, 'qty' => '1', 'unit_price_minor' => $price, 'line_total_minor' => $calc['lines'][0]['total']]],
        'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['total']],
        'payments' => [['method' => 'momo', 'amount_minor' => $calc['total'], 'tendered_minor' => $calc['total']] + $payment]]);
}

it('sends a payment request through Paystack, waits for the customer, and records the sale as verified', function () {
    $this->getJson('/api/v1/pos/bootstrap', ['Authorization' => "Bearer {$this->token}"])->assertJsonPath('momo_confirm', 'manual'); // nothing set up yet
    expect(fn () => $this->momo->saveGateway(actorFor($this->cashier), ['provider' => 'paystack']))->toThrow(AccessDenied::class)
        ->and(fn () => $this->momo->saveGateway($this->o, ['provider' => 'paystack', 'credentials' => ['secret_key' => 'pk_live_abc'], 'is_active' => true]))->toThrow(DomainException::class, 'sk_live_');
    $g = $this->momo->saveGateway($this->o, ['provider' => 'paystack', 'credentials' => ['secret_key' => 'sk_test_abc123'], 'is_active' => true]);
    app(Settings::class)->set($this->o, 'momo.confirm', 'prompt');
    $this->getJson('/api/v1/pos/bootstrap', ['Authorization' => "Bearer {$this->token}"])->assertJsonPath('momo_confirm', 'prompt');

    Http::fake([
        'api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline', 'id' => 777, 'display_text' => 'Approve on your phone']]),
        'api.paystack.co/transaction/verify/*' => Http::sequence()->push(['status' => true, 'data' => ['status' => 'ongoing', 'id' => 777]])
            ->push(['status' => true, 'data' => ['status' => 'success', 'id' => 777, 'amount' => 5000]]),
    ]);
    $id = (string) Str::uuid7();
    $h = ['Authorization' => "Bearer {$this->token}"];
    $this->postJson('/api/v1/pos/momo', ['id' => $id, 'network' => 'telecel', 'phone' => '020 123 4567', 'amount_minor' => 5000], $h)->assertCreated()
        ->assertJsonPath('charge.status', 'pending');
    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.paystack.co/charge' && $r['mobile_money'] === ['phone' => '0201234567', 'provider' => 'vod']
        && $r['amount'] === 5000 && $r->hasHeader('Authorization', 'Bearer sk_test_abc123'));
    // The same request again (the till retried) is the same charge: no second prompt on the phone.
    $this->postJson('/api/v1/pos/momo', ['id' => $id, 'network' => 'telecel', 'phone' => '020 123 4567', 'amount_minor' => 5000], $h)->assertCreated();
    Http::assertSentCount(1);

    // Not paid yet: a sale cannot use it.
    expect(fn () => momoSale($this, ['provider' => 'telecel', 'momo_charge_id' => $id]))->toThrow(DomainException::class, 'not confirmed as paid');
    $this->travel(6)->seconds();
    $this->getJson("/api/v1/pos/momo/{$id}", $h)->assertJsonPath('charge.status', 'pending');
    $this->travel(6)->seconds();
    $this->getJson("/api/v1/pos/momo/{$id}", $h)->assertJsonPath('charge.status', 'success')->assertJsonPath('charge.reference', '777');

    $sale = momoSale($this, ['provider' => 'mtn', 'reference' => 'typed', 'momo_charge_id' => $id])['sale'];
    $pay = SalePayment::where('sale_id', $sale->id)->sole();
    expect($pay->status)->toBe('verified')->and($pay->reference)->toBe('777')->and($pay->provider)->toBe('telecel')->and($pay->momo_charge_id)->toBe($id)
        ->and(MomoCharge::find($id)->sale_id)->toBe($sale->id);
    expect(fn () => momoSale($this, ['provider' => 'telecel', 'momo_charge_id' => $id]))->toThrow(DomainException::class, 'already used');

    // Typed IDs still work in "prompt"; in "prompt_only" only while offline.
    expect(momoSale($this, ['provider' => 'mtn', 'reference' => '12345678'])['sale']->payments->first()->status)->toBe('confirmed_by_cashier');
    app(Settings::class)->set($this->o, 'momo.confirm', 'prompt_only');
    expect(fn () => momoSale($this, ['provider' => 'mtn', 'reference' => '12345678']))->toThrow(DomainException::class, 'payment request');

    // The list in the back office.
    app('auth')->forgetGuards();
    $this->actingAs($this->owner, 'web');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(ListMomoCharges::class)->assertCanSeeTableRecords([MomoCharge::find($id)])->assertSee('Paystack');
});

it('trusts only signed Paystack callbacks, and counts a payment made after the till gave up', function () {
    $g = $this->momo->saveGateway($this->o, ['provider' => 'paystack', 'credentials' => ['secret_key' => 'sk_test_abc123'], 'is_active' => true]);
    Http::fake(['api.paystack.co/charge' => Http::response(['status' => true, 'data' => ['status' => 'pay_offline', 'id' => 9]]),
        'api.paystack.co/transaction/verify/*' => Http::sequence()->push(['status' => true, 'data' => ['status' => 'ongoing', 'id' => 9]])
            ->push(['status' => true, 'data' => ['status' => 'success', 'id' => 9, 'amount' => 5000]])]);
    $c = $this->momo->request($this->device, $this->cashier, ['id' => (string) Str::uuid7(), 'network' => 'mtn', 'phone' => '0241112222', 'amount_minor' => 5000]);
    $this->travel(4)->minutes();
    expect($this->momo->status($this->device, $c->id)->status)->toBe('expired');

    $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $c->reference]]);
    $url = route('momo.webhook', ['token' => $g->webhook_token]);
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => 'forged'], $body)->assertNotFound();
    expect($c->fresh()->status)->toBe('expired');
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_abc123')], $body)->assertOk();
    expect($c->fresh()->status)->toBe('success')->and($c->fresh()->sale_id)->toBeNull(); // shows as "Paid, not on a sale"
});

it('collects through any other provider behind the custom gateway contract, with signed callbacks', function () {
    $secret = str_repeat('s3cret-', 5);
    expect(fn () => $this->momo->saveGateway($this->o, ['provider' => 'custom', 'credentials' => ['url' => 'http://gw.example.test', 'secret' => $secret], 'is_active' => true]))
        ->toThrow(DomainException::class, 'https://');
    $g = $this->momo->saveGateway($this->o, ['provider' => 'custom', 'credentials' => ['url' => 'https://gw.example.test/momo/', 'secret' => $secret], 'is_active' => true]);
    Http::fake([
        'gw.example.test/momo/charges' => Http::response(['status' => 'otp', 'transaction_id' => 'FW-1', 'message' => 'Type the code the customer received']),
        'gw.example.test/momo/charges/*/otp' => Http::response(['status' => 'pending', 'transaction_id' => 'FW-1']),
        'gw.example.test/momo/charges/*' => Http::response(['status' => 'success', 'transaction_id' => 'FW-1', 'amount_minor' => 5000]),
    ]);
    $c = $this->momo->request($this->device, $this->cashier, ['id' => (string) Str::uuid7(), 'network' => 'telecel', 'phone' => '0201234567', 'amount_minor' => 5000]);
    expect($c->status)->toBe('otp');
    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://gw.example.test/momo/charges' && $r->hasHeader('Authorization', "Bearer {$secret}")
        && $r['network'] === 'telecel' && $r['phone'] === '233201234567' && $r['amount_minor'] === 5000 && $r['reference'] === $c->reference
        && $r['callback_url'] === route('momo.webhook', ['token' => $g->webhook_token]));
    expect($this->momo->otp($this->device, $c->id, '123456')->status)->toBe('pending');

    $body = json_encode(['reference' => $c->reference, 'status' => 'success']);
    $url = route('momo.webhook', ['token' => $g->webhook_token]);
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'forged'], $body)->assertNotFound();
    $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, $secret)], $body)->assertOk();
    expect($c->fresh()->status)->toBe('success')->and($c->fresh()->provider_ref)->toBe('FW-1'); // confirmed by asking the gateway, not by the callback alone
});

it('collects through Hubtel, confirming the unsigned callback with Hubtel’s status check', function () {
    $g = $this->momo->saveGateway($this->o, ['provider' => 'hubtel', 'credentials' => ['client_id' => 'cid', 'client_secret' => 'sec', 'pos_sales_id' => '2020'], 'is_active' => true]);
    Http::fake([
        'rmp.hubtel.com/*' => Http::response(['ResponseCode' => '0001', 'Data' => ['TransactionId' => 'H-55']]),
        'api-txnstatus.hubtel.com/*' => Http::response(['responseCode' => '0000', 'data' => ['status' => 'Paid', 'amount' => 50.00, 'transactionId' => 'H-55']]),
    ]);
    $c = $this->momo->request($this->device, $this->cashier, ['id' => (string) Str::uuid7(), 'network' => 'at', 'phone' => '0271234567', 'amount_minor' => 5000]);
    expect($c->status)->toBe('pending');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'merchants/2020/receive/mobilemoney') && $r['Channel'] === 'tigo-gh' && $r['CustomerMsisdn'] === '233271234567'
        && $r['Amount'] == 50.0 && $r['ClientReference'] === $c->reference && $r->hasHeader('Authorization', 'Basic '.base64_encode('cid:sec')));

    $this->postJson(route('momo.webhook', ['token' => $g->webhook_token]), ['ResponseCode' => '0000', 'Data' => ['ClientReference' => $c->reference]])->assertOk();
    expect($c->fresh()->status)->toBe('success')->and($c->fresh()->provider_ref)->toBe('H-55');
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'transactions/2020/status') && str_contains($r->url(), 'clientReference='.$c->reference));
    expect(momoSale($this, ['provider' => 'at', 'momo_charge_id' => $c->id])['sale']->payments->first()->status)->toBe('verified');
});
