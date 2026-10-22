<?php

use App\Domain\Access\AccessService;
use App\Domain\Catalog\CatalogService;
use App\Domain\Devices\DeviceService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Messaging\PaymentAlerts;
use App\Domain\Pos\CashControl;
use App\Domain\Support\DomainException;
use App\Models\Message;
use App\Models\PriceList;
use App\Models\SaleLine;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Till;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-22 10:00', 'Africa/Accra'));
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $this->wh = Warehouse::find($this->till->warehouse_id);
    $this->retail = PriceList::where('code', 'RETAIL')->first();
    $this->a = actorFor($this->owner);
    $this->amox = makeProduct($this->owner, 'AMOX', 'Amoxicillin 500mg', retail: '2.00', tax: 'EXEMPT');
    app(CatalogService::class)->saveProduct($this->a, $this->amox, ['dosage' => '1 capsule 3 times daily for 5 days']);
    app(InventoryService::class)->adjust($this->a, $this->wh, $this->amox, $this->amox->units->first(), '100', 'opening', '');

    [$device, $code] = app(DeviceService::class)->register($this->a, ['branch_id' => $this->branches['HQ']->id, 'till_id' => $this->till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $this->deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $this->cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    app(AccessService::class)->setPin($this->a, $this->cashier, '4826');
    $this->manager = staff($this->owner, 'kojo', 4, 'branch_manager', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin($this->a, $this->manager, '7391');
    $this->token = $this->withToken($this->deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();
});

afterEach(fn () => Carbon::setTestNow());

function r4Sale($t, string $shiftId, array $payment, ?string $dosage = null): array
{
    $unit = $t->amox->units->first();
    $line = ['product_id' => $t->amox->id, 'product_uom_id' => $unit->id, 'qty' => '15', 'unit_price_minor' => 200, 'line_total_minor' => 3000] + ($dosage ? ['dosage' => $dosage] : []);

    $t->seq = ($t->seq ?? 0) + 1;

    return ['id' => (string) Str::uuid7(), 'device_seq' => $t->seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $t->retail->id, 'shift_id' => $shiftId,
        'lines' => [$line], 'totals' => ['net_minor' => 3000, 'tax_minor' => 0, 'total_minor' => 3000], 'payments' => [$payment + ['amount_minor' => 3000, 'tendered_minor' => 3000]]];
}

it('sends the usual dosage to the till and keeps the directions given on each sale line', function () {
    expect($this->amox->fresh()->dosage)->toBe('1 CAPSULE 3 TIMES DAILY FOR 5 DAYS');
    $products = collect($this->withToken($this->token)->getJson('/api/v1/pos/catalog')->assertOk()->json('products'));
    expect($products->firstWhere('id', $this->amox->id)['dosage'])->toBe('1 CAPSULE 3 TIMES DAILY FOR 5 DAYS');

    $shift = $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0])->assertCreated()->json('shift.id');
    $r = $this->withToken($this->token)->postJson('/api/v1/pos/sales', r4Sale($this, $shift, ['method' => 'cash'], '2 capsules twice daily'))->assertCreated()->json('sale');
    expect($r['lines'][0]['dosage'])->toBe('2 CAPSULES TWICE DAILY')->and(SaleLine::sole()->dosage)->toBe('2 CAPSULES TWICE DAILY');
});

it('alerts the owner and accountant of each MoMo payment, by the bell and by SMS, and sends a daily summary', function () {
    $s = app(Settings::class);
    $s->set($this->a, 'alerts.payments', 'each_summary');
    $s->set($this->a, 'alerts.by_sms', true);
    $s->set($this->a, 'alerts.payment_min_minor', 1000);
    $accountant = staff($this->owner, 'efua', 4, 'accountant');
    app(AccessService::class)->updateUser($this->a, $accountant, ['phone' => '0244000111']);
    $teller = staff($this->owner, 'yaw', 2, 'teller', ScopeType::Till, $this->till->id); // no alerts for tellers

    $shift = $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0])->assertCreated()->json('shift.id');
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', r4Sale($this, $shift, ['method' => 'momo', 'provider' => 'mtn', 'reference' => 'MP2610']))->assertCreated();
    $this->withToken($this->token)->postJson('/api/v1/pos/sales', r4Sale($this, $shift, ['method' => 'cash']))->assertCreated(); // cash: no alert

    expect($accountant->notifications()->count())->toBe(1)
        ->and($accountant->notifications()->first()->data['title'])->toBe('MTN MoMo GH₵ 30.00 received')
        ->and($this->owner->notifications()->where('data->title', 'MTN MoMo GH₵ 30.00 received')->exists())->toBeTrue()
        ->and($teller->notifications()->count())->toBe(0);
    $sms = Message::where('template_key', 'payment_alert')->where('channel', 'sms')->sole();
    expect($sms->to)->toBe('0244000111')->and($sms->status)->toBe('queued')->and($sms->body)->toContain('MP2610');

    // The summary waits for its time (21:00), then goes once.
    expect(app(PaymentAlerts::class)->dailySummaries())->toBe(0);
    Carbon::setTestNow(Carbon::parse('2026-10-22 21:05', 'Africa/Accra'));
    expect(app(PaymentAlerts::class)->dailySummaries())->toBeGreaterThanOrEqual(2)
        ->and(app(PaymentAlerts::class)->dailySummaries())->toBe(0)
        ->and($accountant->notifications()->where('data->title', 'like', 'Mobile money and card%')->value('data')['body'])->toContain('MTN MoMo: GH₵ 30.00 (1)');
});

it('names each till session by shift, needs a manager outside trading hours or to open again, and makes a long one a straight day', function () {
    app(Settings::class)->set($this->a, 'pos.auto_shifts', true);
    expect(ShiftTemplate::where('name', 'Morning')->value('ends_at'))->toStartWith('15:00');

    $open = fn (array $extra = []) => $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0] + $extra);
    $close = fn () => $this->withToken($this->token)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 0]);

    $open()->assertCreated()->assertJsonPath('shift.shift_name', 'Morning');
    $close()->assertOk();
    $open()->assertStatus(422)->assertJsonPath('error', 'till_closed');
    $open(['grant' => ['username' => 'kojo', 'pin' => '0000', 'reason' => 'Cover']])->assertStatus(422)->assertJsonPath('error', 'approval_failed');
    $open(['grant' => ['username' => 'kojo', 'pin' => '7391', 'reason' => 'Afternoon teller is sick']])->assertCreated();
    expect(Shift::where('status', 'open')->sole()->granted_by_name)->toBe('Kojo');
    $close()->assertOk();

    // 05:30: outside 07:00–23:00.
    Carbon::setTestNow(Carbon::parse('2026-10-23 05:30', 'Africa/Accra'));
    $open()->assertStatus(422)->assertJsonPath('error', 'outside_hours');
    Carbon::setTestNow(Carbon::parse('2026-10-23 07:30', 'Africa/Accra'));
    $open()->assertCreated();
    Carbon::setTestNow(Carbon::parse('2026-10-23 18:30', 'Africa/Accra'));
    $close()->assertOk();
    expect(Shift::orderByDesc('opened_at')->first()->shift_name)->toBe('Straight day');

    // Shifts may overlap by at most an hour.
    expect(fn () => app(CashControl::class)->saveTemplate($this->a, new ShiftTemplate, ['name' => 'Evening', 'starts_at' => '20:00', 'ends_at' => '23:00']))
        ->toThrow(DomainException::class, 'overlaps Afternoon by 3 h');
});
