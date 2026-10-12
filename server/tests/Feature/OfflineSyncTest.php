<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Devices\DeviceService;
use App\Domain\Devices\DeviceSignature;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\TillFileImport;
use App\Domain\Pricing\PromotionService;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\DomainException;
use App\Models\PriceList;
use App\Models\Promotion;
use App\Models\ReviewItem;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\StockLevel;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use Illuminate\Support\Carbon;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $this->wh = Warehouse::find($this->till->warehouse_id);
    $this->milo = makeProduct($this->owner, 'MILO', 'Milo 400g', retail: '58.00');
    $this->bread = makeProduct($this->owner, 'BREAD', 'Bread', retail: '25.00', tax: 'EXEMPT');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->milo, $this->milo->units->first(), '10', 'opening', '');
    app(InventoryService::class)->adjust(actorFor($this->owner), $this->wh, $this->bread, $this->bread->units->first(), '1', 'opening', '');
    $this->cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->cashier, '482613');
    $this->t = fakeTill($this, $this->till, 'esi', '482613');
});

function onHandOf($product, $wh): string
{
    return (string) StockLevel::where('warehouse_id', $wh->id)->where('product_id', $product->id)->value('on_hand');
}

it('issues an offline grant whose PIN check the till can verify without the server', function () {
    $g = $this->t->grant;
    $hash = base64_encode(hash_pbkdf2('sha256', '482613', base64_decode($g['pin']['salt']), $g['pin']['iterations'], 32, true));

    expect($g['pin']['hash'])->toBe($hash)
        ->and($g['user_id'])->toBe($this->cashier->id)
        ->and(now()->diffInHours($g['expires_at']))->toBeGreaterThan(71.0);
});

it('applies a whole offline shift in order: open, sales, close', function () {
    $at = fn ($m) => now()->subMinutes($m)->toIso8601String();
    $r = $this->t->sync([
        $this->t->openShift(20000, $at(60)),
        $this->t->sale([[$this->milo, '2', null]], $at(50)),
        $this->t->sale([[$this->milo, '1', null], [$this->bread, '1', null]], $at(40)),
        $this->t->closeShift(20000 + 11600 + 8300, $at(5)),
    ])->assertOk()->json('results');

    expect(collect($r)->pluck('status')->all())->toBe(['accepted', 'accepted', 'accepted', 'accepted'])
        ->and($r[1]['receipt_no'])->toBe('HQ-T01'.$this->t->device->receipt_series.'-000001')
        ->and(onHandOf($this->milo, $this->wh))->toBe('7.000')
        ->and(Shift::find($this->t->shiftId)->status)->toBe('closed')
        ->and(Shift::find($this->t->shiftId)->over_short_minor)->toBe(0)
        ->and(Sale::where('offline', true)->count())->toBe(2)
        ->and(ReviewItem::count())->toBe(0);
});

it('treats a resent batch as already done (duplicate replay)', function () {
    $events = [$this->t->openShift(0, now()->subHour()->toIso8601String()), $this->t->sale([[$this->milo, '1', null]], now()->subMinutes(30)->toIso8601String())];
    $this->t->sync($events)->assertOk();
    $again = $this->t->sync($events)->assertOk()->json('results');

    expect(collect($again)->pluck('status')->all())->toBe(['duplicate', 'duplicate'])
        ->and(Sale::count())->toBe(1)
        ->and(onHandOf($this->milo, $this->wh))->toBe('9.000');
});

it('refuses a batch not signed by the device key, even with a valid token', function () {
    $this->t->sync([$this->t->openShift(0, now()->toIso8601String())], base64_encode(random_bytes(64)))
        ->assertUnauthorized()->assertJson(['error' => 'bad_signature']);
    expect(Shift::count())->toBe(0);
});

it('rejects a forged offline grant and records it for review', function () {
    $event = $this->t->openShift(0, now()->toIso8601String());
    $event['grant']['user_id'] = $this->owner->id; // impersonate the owner

    $r = $this->t->sync([$event])->assertOk()->json('results.0');

    expect($r['status'])->toBe('rejected')->and($r['code'])->toBe('invalid_grant')
        ->and(ReviewItem::where('type', 'sync_failed')->exists())->toBeTrue();
});

it('keeps an offline sale made at the old price after HQ changed it, and flags it', function () {
    $sold = now()->subMinutes(30)->toIso8601String();
    $events = [$this->t->openShift(0, now()->subHour()->toIso8601String()), $this->t->sale([[$this->milo, '1', null]], $sold)];
    // HQ raises the price while the till is offline.
    $retail = PriceList::where('code', 'RETAIL')->first();
    app(CatalogService::class)->saveProduct(actorFor($this->owner), $this->milo, ['prices' => [
        ['price_list_id' => $retail->id, 'uom_id' => $this->milo->units->first()->uom_id, 'min_qty' => '1', 'amount' => '62.00'],
    ]]);

    $r = $this->t->sync($events)->assertOk()->json('results');

    expect($r[1]['status'])->toBe('accepted')
        ->and(Sale::first()->total_minor)->toBe(5800) // what the customer actually paid
        ->and(ReviewItem::where('type', 'offline_price')->first()->details['lines'][0]['current_minor'])->toBe(6200);
});

it('accepts two tills selling the last unit offline, and flags the negative stock', function () {
    $other = fakeTill($this, $this->till, 'esi', '482613');
    $this->t->sync([$this->t->openShift(0, now()->subHour()->toIso8601String()), $this->t->sale([[$this->bread, '1', null]], now()->subMinutes(20)->toIso8601String())])->assertOk();
    $r = $other->sync([$other->openShift(0, now()->subHour()->toIso8601String()), $other->sale([[$this->bread, '1', null]], now()->subMinutes(19)->toIso8601String())])
        ->assertOk()->json('results');

    expect($r[1]['status'])->toBe('accepted')
        ->and(onHandOf($this->bread, $this->wh))->toBe('-1.000')
        ->and(ReviewItem::where('type', 'negative_stock')->count())->toBe(1)
        ->and(Sale::pluck('receipt_no')->unique())->toHaveCount(2); // different series per device
});

it('applies the good events of a batch even when one in the middle is bad (partial failure)', function () {
    $open = $this->t->openShift(0, now()->subHour()->toIso8601String());
    $bad = $this->t->sale([[$this->milo, '1', null]], now()->subMinutes(20)->toIso8601String());
    $bad['payload']['totals']['total_minor'] += 1; // corrupted on the till

    $r = $this->t->sync([
        $open,
        $bad,
        $this->t->sale([[$this->bread, '1', null]], now()->subMinutes(10)->toIso8601String()),
    ])->assertOk()->json('results');

    expect(collect($r)->pluck('status')->all())->toBe(['accepted', 'rejected', 'accepted'])
        ->and($r[1]['code'])->toBe('totals_mismatch')
        ->and(Sale::count())->toBe(1)
        ->and(ReviewItem::where('type', 'sync_failed')->count())->toBe(1);
});

it('keeps sales from a till with a wrong clock, and flags them (clock skew)', function () {
    $r = $this->t->sync([
        $this->t->openShift(0, now()->subHour()->toIso8601String()),
        $this->t->sale([[$this->milo, '1', null]], now()->addHours(3)->toIso8601String()),
    ])->assertOk()->json('results');

    expect($r[1]['status'])->toBe('accepted')->and(ReviewItem::where('type', 'clock_skew')->exists())->toBeTrue();
});

it('syncs a week of offline trading: sales after the grant expired are kept and flagged', function () {
    $start = Carbon::parse($this->t->grant['issued_at']); // the 72 h boundary is measured from issue, to the second
    $events = [$this->t->openShift(0, $start->copy()->toIso8601String())];
    for ($day = 0; $day < 7; $day++) {
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->t->sale([[$this->bread, '1', 2500]], $start->copy()->addDays($day)->addMinutes($i * 10)->toIso8601String());
        }
    }
    $this->travel(7)->days();

    $r = $this->t->sync($events)->assertOk()->json('results');

    expect(collect($r)->where('status', 'accepted'))->toHaveCount(36)
        ->and(Sale::count())->toBe(35)
        ->and(onHandOf($this->bread, $this->wh))->toBe('-34.000')
        ->and(ReviewItem::where('type', 'grant_expired')->count())->toBe(19); // after 72 h: day 3 from minute 10 (4) + days 4–6 (15); exactly 72 h is still inside
});

it('keeps sales by a person deactivated before the till synced, and flags them', function () {
    $events = [$this->t->openShift(0, now()->subHour()->toIso8601String()), $this->t->sale([[$this->milo, '1', null]], now()->subMinutes(30)->toIso8601String())];
    app(AccessService::class)->updateUser(actorFor($this->owner), $this->cashier, ['is_active' => false], 'Dismissed');

    $r = $this->t->sync($events)->assertOk()->json('results');

    expect($r[1]['status'])->toBe('accepted')->and(ReviewItem::where('type', 'grant_revoked')->exists())->toBeTrue();
});

it('recovers a revoked till’s unsent sales from its exported queue file', function () {
    $events = [$this->t->openShift(0, now()->subHour()->toIso8601String()), $this->t->sale([[$this->milo, '2', null]], now()->subMinutes(30)->toIso8601String())];
    $body = json_encode(['events' => $events]);
    openssl_sign($body, $der, $this->t->privateKey, OPENSSL_ALGO_SHA256);
    $file = json_encode(['format' => 'synclytics-till-queue-1', 'device_id' => $this->t->device->id, 'body' => $body,
        'signature' => base64_encode(DeviceSignature::derToRaw($der))]);
    // The till is wiped and revoked before it could sync.
    app(DeviceService::class)->revoke(actorFor($this->owner), $this->t->device->fresh(), 'Reinstalled');

    $r = app(TillFileImport::class)->import(actorFor($this->owner), $file);

    expect(collect($r['results'])->pluck('status')->all())->toBe(['accepted', 'accepted'])
        ->and(onHandOf($this->milo, $this->wh))->toBe('8.000');
    // A tampered file is refused.
    $bad = json_decode($file, true);
    $bad['body'] = str_replace('"2"', '"1"', $bad['body']);
    expect(fn () => app(TillFileImport::class)->import(actorFor($this->owner), json_encode($bad)))
        ->toThrow(DomainException::class, 'signature');
});

it('never reuses receipt numbers when a till is reinstalled mid-queue', function () {
    // The old till still holds an unsent sale #1; the reinstalled one starts its own series at #1.
    $old = $this->t;
    $queued = [$old->openShift(0, now()->subHour()->toIso8601String()), $old->sale([[$this->milo, '1', null]], now()->subMinutes(40)->toIso8601String())];
    $new = fakeTill($this, $this->till, 'esi', '482613');
    $new->sync([$new->openShift(0, now()->subMinutes(30)->toIso8601String()), $new->sale([[$this->milo, '1', null]], now()->subMinutes(20)->toIso8601String())])->assertOk();

    $old->sync($queued)->assertOk();

    expect(Sale::orderBy('receipt_no')->pluck('receipt_no')->all())
        ->toBe(['HQ-T01'.$old->device->receipt_series.'-000001', 'HQ-T01'.$new->device->receipt_series.'-000001'])
        ->and($old->device->receipt_series)->not->toBe($new->device->receipt_series);
});

it('keeps an offline sale that missed a promotion started while the till was offline, and flags it', function () {
    app(PromotionService::class)->save(actorFor($this->owner), new Promotion,
        ['name' => 'Milo 10%', 'type' => 'percent', 'product_ids' => [$this->milo->id], 'value_bp' => 1000]);
    $at = now()->subMinutes(30)->toIso8601String();

    $r = $this->t->sync([$this->t->openShift(0, now()->subHour()->toIso8601String()), $this->t->sale([[$this->milo, '1', null]], $at)])->assertOk();

    $sale = Sale::latest('received_at')->first();
    expect(collect($r->json('results'))->pluck('status')->unique()->all())->toBe(['accepted'])
        ->and($sale->total_minor)->toBe(5800)->and($sale->discount_minor)->toBe(0)
        ->and(ReviewItem::where('type', 'offline_price')->where('summary', 'like', '%Promotions or discounts%')->exists())->toBeTrue();
});

it('accepts an offline discount approved by a supervisor with the limit, and flags one without', function () {
    $sup = staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->branches['HQ']->id);
    $open = $this->t->openShift(0, now()->subHour()->toIso8601String());
    $make = function (?string $approver) {
        $e = $this->t->sale([[$this->milo, '1', null]], now()->subMinutes(20)->toIso8601String());
        $e['payload']['lines'][0]['manual'] = ['bp' => 500];
        $e['payload']['lines'][0]['line_total_minor'] = 5510;
        $t = TaxCalculator::line(5510, $this->milo->taxGroup->rates->map(fn ($r) => ['code' => $r->code, 'name' => $r->name, 'rate_bp' => $r->rate_bp])->all(), true);
        $e['payload']['totals'] = ['net_minor' => $t['net'], 'tax_minor' => $t['tax'], 'total_minor' => 5510];
        $e['payload']['payments'][0]['amount_minor'] = $e['payload']['payments'][0]['tendered_minor'] = 5510;
        if ($approver) {
            $e['payload']['discount_approved_offline_by'] = $approver;
        }

        return $e;
    };
    $this->t->sync([$open, $make($sup->id), $make(null)])->assertOk();

    $sales = Sale::orderBy('device_seq')->get();
    expect($sales[0]->discount_approved_by_name)->toBe('Kojo')->and($sales[0]->total_minor)->toBe(5510)
        ->and(ReviewItem::where('type', 'discount_over_limit')->count())->toBe(1);
});
