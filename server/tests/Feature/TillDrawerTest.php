<?php

use App\Domain\Access\AccessService;
use App\Domain\Analytics\LossPrevention;
use App\Domain\Devices\DeviceService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Pos\DrawerService;
use App\Domain\Pos\ShiftService;
use App\Filament\Resources\Shifts\Pages\ListShifts;
use App\Filament\Resources\TillDrawerEvents\Pages\ListTillDrawerEvents;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\ExpenseClaim;
use App\Models\ReviewItem;
use App\Models\Shift;
use App\Models\Till;
use App\Models\TillDrawerEvent;
use App\Security\ScopeType;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    [$device, $code] = app(DeviceService::class)->register(actorFor($this->owner),
        ['branch_id' => $this->branches['HQ']->id, 'till_id' => $this->till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $this->device = $device;
    $deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $this->cashier = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    $this->supervisor = staff($this->owner, 'kojo', 3, 'supervisor', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->cashier, '4826');
    app(AccessService::class)->setPin(actorFor($this->owner), $this->supervisor, '7391');
    $this->manager = staff($this->owner, 'ama', 4, 'branch_manager', ScopeType::Branch, $this->branches['HQ']->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->manager, '5602');
    $this->token = $this->withToken($deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();
    $this->shiftId = $this->withToken($this->token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 50000])->assertCreated()->json('shift.id');
    $this->transport = Account::where('name', 'Transport and fuel')->first();
    $this->drawer = fn (array $body) => $this->withToken($this->token)->postJson('/api/v1/pos/drawer', ['id' => (string) Str::uuid7()] + $body);
});

it('tells the till the pay-out categories and limits', function () {
    $d = $this->withToken($this->token)->getJson('/api/v1/pos/bootstrap')->assertOk()->json('drawer');
    $names = collect($d['categories'])->pluck('name');
    expect($d['payout_approval_minor'])->toBe(10000)->and($d['cash_limit_minor'])->toBe(0)
        ->and($names)->toContain('Transport and fuel')->not->toContain('Cash over and short')->not->toContain('Salaries and wages')->not->toContain('Cost of goods sold');
});

it('pays an expense out of the drawer: an expense claim paid from the till, posted to the books, and less cash expected', function () {
    ($this->drawer)(['kind' => 'payout', 'amount_minor' => 4500, 'account_id' => $this->transport->id, 'payee' => 'Taxi', 'reason' => 'Taxi to the bank', 'reference' => 'R-12',
        'photo' => 'data:image/png;base64,'.base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='))])
        ->assertCreated()->assertJsonPath('event.approved_by', null);
    $claim = ExpenseClaim::sole();
    expect($claim->status)->toBe('paid')->and($claim->amount_minor)->toBe(4500)->and($claim->claimant_name)->toBe($this->cashier->name)
        ->and($claim->description)->toBe('Till pay-out: Taxi to the bank')->and($claim->receipt_path)->toStartWith('expense-receipts/till-')
        ->and(Account::find($claim->paid_from_account_id)->system_key)->toBe('cash_till');

    app(AutoPoster::class)->run($this->tenant->id);
    $posted = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->whereIn('a.name', ['Transport and fuel', 'Cash on hand – tills'])
        ->groupBy('a.name')->selectRaw('a.name, SUM(l.amount_minor) AS s')->pluck('s', 'name');
    expect((int) $posted['Transport and fuel'])->toBe(4500)->and((int) $posted['Cash on hand – tills'])->toBe(-4500);

    $closed = $this->withToken($this->token)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 45500])->assertOk()->json('shift');
    expect($closed['expected_cash_minor'])->toBe(45500)->and($closed['over_short_minor'])->toBe(0);
});

it('needs a supervisor above the pay-out limit and for every cash drop, never the cashier themselves', function () {
    $big = ['kind' => 'payout', 'amount_minor' => 25000, 'account_id' => $this->transport->id, 'reason' => 'Generator fuel'];
    ($this->drawer)($big)->assertStatus(422)->assertJson(['error' => 'drawer_approval_needed']);
    ($this->drawer)($big + ['approval' => ['username' => 'esi', 'pin' => '4826']])->assertStatus(422);
    ($this->drawer)($big + ['approval' => ['username' => 'kojo', 'pin' => '7391']])->assertCreated()->assertJsonPath('event.approved_by', $this->supervisor->name);
    expect(ExpenseClaim::sole()->approved_by_name)->toBe($this->supervisor->name);

    ($this->drawer)(['kind' => 'drop', 'amount_minor' => 20000])->assertStatus(422)->assertJson(['error' => 'drawer_approval_needed']);
    ($this->drawer)(['kind' => 'drop', 'amount_minor' => 20000, 'approval' => ['username' => 'kojo', 'pin' => '7391']])->assertCreated();
    ($this->drawer)(['kind' => 'payin', 'amount_minor' => 5000])->assertStatus(422); // a reason is needed
    ($this->drawer)(['kind' => 'payin', 'amount_minor' => 5000, 'reason' => 'Change from the safe'])->assertCreated();
    ($this->drawer)(['kind' => 'no_sale', 'reason' => 'Change for a customer', 'approval' => ['username' => 'ama', 'pin' => '5602']])->assertCreated();

    expect(CashMovement::orderBy('created_at')->get(['kind', 'from_account', 'to_account', 'amount_minor'])->map->only('kind', 'from_account', 'to_account', 'amount_minor')->all())->toBe([
        ['kind' => 'till_drop', 'from_account' => 'cash_till', 'to_account' => 'cash_office', 'amount_minor' => 20000],
        ['kind' => 'till_payin', 'from_account' => 'cash_office', 'to_account' => 'cash_till', 'amount_minor' => 5000],
    ]);
    // 500 float − 250 paid out − 200 dropped + 50 paid in = 100.
    expect(app(ShiftService::class)->expectedCash(Shift::find($this->shiftId)))->toBe(10000)
        ->and(fn () => DB::transaction(fn () => DB::table('till_drawer_events')->update(['amount_minor' => 1])))->toThrow(Exception::class); // append-only

    // Vouchers and coupons at the close: counted in money and in pieces.
    $closed = $this->withToken($this->token)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 10000, 'other' => ['coupons' => 2, 'vouchers' => 0]])
        ->assertOk()->json('shift');
    expect(collect($closed['other'])->keyBy('key')->map(fn ($c) => [$c['expected'], $c['counted'], $c['diff'], $c['pieces']])->all())
        ->toBe(['vouchers' => [0, 0, 0, false], 'coupons' => [0, 2, 2, true]]);

    // Back office: the drawer list and the shift line; no-sale opens in loss prevention.
    app('auth')->forgetGuards();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->owner), 'web');
    Livewire::test(ListTillDrawerEvents::class)->assertSee('Generator fuel')->assertSee('Cash drop to the safe')->assertSee($this->supervisor->name)
        ->filterTable('kind', 'no_sale')->assertSee('Change for a customer')->assertDontSee('Generator fuel');
    Livewire::test(ListShifts::class)->assertSee('Paid out GH₵ 250.00')->assertSee('Dropped GH₵ 200.00')->assertSee('1 no-sale open')
        ->callTableAction('report', $this->shiftId)->assertFileDownloaded();
    $row = collect(app(LossPrevention::class)->cashiers($this->tenant->id, CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay()))
        ->firstWhere('user_id', $this->cashier->id);
    expect($row)->toBeNull(); // no sales: listed only with receipts
    expect(TillDrawerEvent::where('kind', 'no_sale')->count())->toBe(1);
});

it('warns supervisors once when the drawer goes above the cash limit', function () {
    ($this->drawer)(['kind' => 'limit_alert', 'amount_minor' => 250000])->assertCreated();
    expect(DB::table('notifications')->where('notifiable_id', $this->supervisor->id)->count())->toBe(1)
        ->and(DB::table('notifications')->where('notifiable_id', $this->cashier->id)->count())->toBe(0);
});

it('records drawer events made offline, once', function () {
    $t = fakeTill($this, Till::where('branch_id', $this->branches['HQ']->id)->skip(1)->first() ?? $this->till, 'esi', '4826');
    $at = fn ($m) => now()->subMinutes($m)->toIso8601String();
    $payout = ['id' => (string) Str::uuid7(), 'type' => 'drawer', 'grant' => $t->grant, 'payload' => ['kind' => 'payout', 'amount_minor' => 3000,
        'account_id' => $this->transport->id, 'reason' => 'Water', 'occurred_at' => $at(20)]];
    $events = [$t->openShift(10000, $at(30)), $payout, ['id' => (string) Str::uuid7(), 'type' => 'drawer', 'grant' => $t->grant,
        'payload' => ['kind' => 'drop', 'amount_minor' => 5000, 'approved_by' => $this->supervisor->id, 'occurred_at' => $at(10)]], $t->closeShift(2000, $at(5))];
    $res = $t->sync($events)->assertOk()->json('results');
    expect(collect($res)->pluck('status')->all())->toBe(['accepted', 'accepted', 'accepted', 'accepted'], json_encode($res));
    expect(collect($t->sync([$payout])->assertOk()->json('results'))->pluck('status')->all())->toBe(['duplicate'])
        ->and(TillDrawerEvent::where('offline', true)->count())->toBe(2)
        ->and(TillDrawerEvent::where('kind', 'drop')->value('approved_by_name'))->toBe($this->supervisor->name)
        ->and(Shift::find($t->shiftId)->expected_cash_minor)->toBe(2000)->and(Shift::find($t->shiftId)->over_short_minor)->toBe(0);
});

it('needs a manager to open the drawer without a sale, unless switched off; offline mistakes go to the review list', function () {
    $noSale = ['kind' => 'no_sale', 'reason' => 'Change for a customer'];
    expect($this->withToken($this->token)->getJson('/api/v1/pos/bootstrap')->json('drawer.no_sale_approval'))->toBeTrue();
    ($this->drawer)($noSale)->assertStatus(422)->assertJson(['error' => 'drawer_approval_needed']);
    ($this->drawer)($noSale + ['approval' => ['username' => 'kojo', 'pin' => '7391']])->assertStatus(422)->assertJson(['error' => 'over_limit']); // a supervisor is not enough
    ($this->drawer)($noSale + ['approval' => ['username' => 'ama', 'pin' => '5602']])->assertCreated()->assertJsonPath('event.approved_by', $this->manager->name);

    // Offline: the PIN was checked on the till; the server checks the permission and flags what it does not allow.
    app('auth')->forgetGuards();
    $record = fn (?string $approvedBy) => app(DrawerService::class)->record($this->device, $this->cashier,
        ['id' => (string) Str::uuid7(), 'approved_by' => $approvedBy, 'shift_id' => $this->shiftId] + $noSale, offline: true);
    $record($this->manager->id);
    $flagged = $record($this->supervisor->id);
    expect(ReviewItem::where('type', 'drawer_unapproved')->pluck('subject_id')->all())->toBe([$flagged->id]);

    app(Settings::class)->set(actorFor($this->owner), 'pos.no_sale_approval', false);
    ($this->drawer)($noSale)->assertCreated();
    expect(TillDrawerEvent::where('kind', 'no_sale')->count())->toBe(4);
});
