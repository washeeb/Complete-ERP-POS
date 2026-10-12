<?php

use App\Domain\Access\AccessService;
use App\Domain\Devices\DeviceService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Hr\HrService;
use App\Domain\Inventory\InventoryService;
use App\Domain\Pos\CashControl;
use App\Domain\Pricing\PriceResolver;
use App\Domain\Pricing\TaxCalculator;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Filament\Pages\CashDesk;
use App\Filament\Pages\CashSetup;
use App\Filament\Pages\IncomeControl;
use App\Models\Account;
use App\Models\CashHandover;
use App\Models\CashMovement;
use App\Models\CashVariance;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\MoneyTransfer;
use App\Models\PriceList;
use App\Models\Shift;
use App\Models\Till;
use App\Models\Vault;
use App\Models\VaultSession;
use App\Models\Warehouse;
use App\Security\ScopeType;
use App\Support\Qty;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->till = Till::where('branch_id', $this->branches['HQ']->id)->first();
    $wh = Warehouse::find($this->till->warehouse_id);
    $this->bread = makeProduct($this->owner, 'BREAD', 'Sliced bread', retail: '50.00', tax: 'EXEMPT');
    app(InventoryService::class)->adjust(actorFor($this->owner), $wh, $this->bread, $this->bread->units->first(), '20', 'opening', '');

    app(Settings::class)->set(actorFor($this->owner), 'cash.vaults', true);
    $this->cash = app(CashControl::class);
    $this->cash->ensureVaults($this->tenant->id);
    $this->vault = Vault::where('till_id', $this->till->id)->whereHas('shiftTemplate', fn ($q) => $q->where('name', 'Morning'))->firstOrFail();

    [$device, $code] = app(DeviceService::class)->register(actorFor($this->owner),
        ['branch_id' => $this->branches['HQ']->id, 'till_id' => $this->till->id, 'name' => 'Till PC', 'kind' => 'pos']);
    $this->deviceToken = $this->postJson('/api/v1/devices/enroll', ['code' => $code, 'public_key' => str_repeat('k', 64)])->assertCreated()->json('token');
    $this->teller = staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $this->till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->teller, '4826');
    $this->cashier = staff($this->owner, 'kofi', 3, 'cashier', ScopeType::Branch, $this->branches['HQ']->id);
    $this->controller = staff($this->owner, 'ama', 4, 'income_controller');
});

/** Sign the teller in on the till (device token + PIN) and return the session token. */
function tellerToken($t): string
{
    app('auth')->forgetGuards(); // a back-office sign-in earlier in the test must not leak into the till's
    app('auth')->setDefaultDriver(config('auth.defaults.guard'));
    $token = $t->withToken($t->deviceToken)->postJson('/api/v1/pos/login', ['username' => 'esi', 'pin' => '4826'])->assertOk()->json('token');
    app('auth')->forgetGuards();

    return $token;
}

/** A cash sale of $qty loaves at GHS 50.00, built as the till builds it. */
function breadSale($t, string $shiftId, int $qty, int $seq): array
{
    $list = PriceList::where('code', 'RETAIL')->first();
    $unit = $t->bread->units->first();
    $price = app(PriceResolver::class)->resolve($list, $t->bread->id, $unit->id, Qty::toMilli((string) $qty), now());
    $calc = TaxCalculator::line($qty * $price->amount_minor, [], $list->prices_include_tax);

    return ['id' => (string) Str::uuid7(), 'device_seq' => $seq, 'occurred_at' => now()->toIso8601String(), 'price_list_id' => $list->id, 'shift_id' => $shiftId,
        'lines' => [['product_id' => $t->bread->id, 'product_uom_id' => $unit->id, 'qty' => (string) $qty, 'unit_price_minor' => $price->amount_minor, 'line_total_minor' => $calc['gross']]],
        'totals' => ['net_minor' => $calc['net'], 'tax_minor' => $calc['tax'], 'total_minor' => $calc['gross']],
        'payments' => [['method' => 'cash', 'amount_minor' => $calc['gross'], 'tendered_minor' => $calc['gross']]]];
}

function cashBalance(string $key): int
{
    return (int) DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('a.system_key', $key)->sum('l.amount_minor');
}

it('runs the whole chain: float, teller, blind counts with a recount, handover, decision, deposit, and the books', function () {
    // The cashier issues the Morning vault's float to the teller; never to themselves.
    expect(fn () => $this->cash->issueFloat(actorFor($this->cashier), $this->vault, $this->cashier, 20000))->toThrow(DomainException::class);
    $session = $this->cash->issueFloat(actorFor($this->cashier), $this->vault, $this->teller, 20000);
    expect($session->status)->toBe('issued');

    // The till: without the vault it does not open; the float is the vault's, whatever the till types.
    $token = tellerToken($this);
    $boot = $this->withToken($token)->getJson('/api/v1/pos/bootstrap')->assertOk()->json('cash');
    expect($boot['vaults'])->toBeTrue()->and($boot['sessions'][0]['id'])->toBe($session->id)->and(collect($boot['denominations'])->pluck('key'))->toContain('n200');
    $this->withToken($token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0])->assertStatus(422);
    $shiftId = $this->withToken($token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0, 'vault_session_id' => $session->id])
        ->assertCreated()->json('shift.id');
    expect(Shift::find($shiftId)->opening_float_minor)->toBe(20000)->and($session->fresh()->status)->toBe('open');

    // Two loaves in cash (GHS 100.00): GHS 300.00 expected. The teller counts GHS 290.00 note by note, and is not told the expected figure.
    $this->withToken($token)->postJson('/api/v1/pos/sales', breadSale($this, $shiftId, 2, 1))->assertCreated();
    $closed = $this->withToken($token)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 0, 'denoms' => ['n200' => 1, 'n50' => 1, 'n20' => 2]])
        ->assertOk()->json('shift');
    expect($closed['expected_cash_minor'])->toBeNull()->and($closed['over_short_minor'])->toBeNull();
    $session->refresh();
    expect($session->status)->toBe('counted')->and($session->teller_count_minor)->toBe(29000)->and($session->expected_minor)->toBe(30000);

    // The cashier counts blind: GHS 295.00 differs from the teller, so a recount is asked; the second count (GHS 290.00) stands.
    expect(fn () => $this->cash->receiveVault(actorFor($this->teller), $session, ['n200' => 1]))->toThrow(AccessDenied::class);
    $first = $this->cash->receiveVault(actorFor($this->cashier), $session, ['n200' => 1, 'n50' => 1, 'n20' => 2, 'n5' => 1]);
    expect($first['status'])->toBe('recount')->and($session->fresh()->status)->toBe('counted');
    $second = $this->cash->receiveVault(actorFor($this->cashier), $session->fresh(), ['n200' => 1, 'n50' => 1, 'n20' => 2]);
    expect($second['status'])->toBe('received');
    $short = CashVariance::sole();
    expect($short->amount_minor)->toBe(-1000)->and($short->person_id)->toBe($this->teller->id)->and($short->stage)->toBe('teller');

    // The cashier hands over; the income controller counts blind and it matches.
    $handover = $this->cash->handOver(actorFor($this->cashier), $this->branches['HQ'], [$session->id]);
    expect($handover->declared_minor)->toBe(29000)->and($handover->number)->toStartWith('CH-');
    expect(fn () => $this->cash->receiveHandover(actorFor($this->cashier), $handover, null, 29000))->toThrow(AccessDenied::class);
    expect($this->cash->receiveHandover(actorFor($this->controller), $handover, ['n200' => 1, 'n50' => 1, 'n20' => 2])['status'])->toBe('received');
    expect(CashVariance::count())->toBe(1);

    // The shortage: no employee record, so it cannot be charged to pay; booked as cash short instead.
    expect(fn () => $this->cash->decide(actorFor($this->controller), $short, 'charge_staff', 'Miscounted change'))->toThrow(DomainException::class, 'no employee record');
    $this->cash->decide(actorFor($this->controller), $short->fresh(), 'over_short', 'Miscounted change');
    expect($short->fresh()->status)->toBe('approved');

    // The deposit to the bank, as a money transfer.
    $bank = Account::where('system_key', 'bank')->first();
    $this->cash->deposit(actorFor($this->controller), $handover->fresh(), $bank, 29000, now()->toDateString(), 'SLIP-1001');
    expect(MoneyTransfer::sole()->reference)->toBe('Deposit slip SLIP-1001');

    // The books: the till, the cashier and the differences account are all back to zero; the GHS 10.00 is cash short.
    app(AutoPoster::class)->run($this->tenant->id);
    expect(cashBalance('cash_till'))->toBe(0)
        ->and(cashBalance('cash_cashier'))->toBe(0)
        ->and(cashBalance('cash_differences'))->toBe(0)
        ->and(cashBalance('cash_over_short'))->toBe(1000)
        ->and(cashBalance('bank'))->toBe(29000)
        ->and(cashBalance('cash_office'))->toBe(-20000); // the float came from the office safe, the takings went to the bank
    expect(DB::table('gl_postings')->where('source_type', 'shift')->where('source_id', $shiftId)->exists())->toBeFalse(); // settled by the chain, not twice
});

it('recovers a shortage from pay when the teller is an employee, and keeps cash movements append-only', function () {
    $session = $this->cash->issueFloat(actorFor($this->cashier), $this->vault, $this->teller, 10000);
    $token = tellerToken($this);
    $shiftId = $this->withToken($token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0, 'vault_session_id' => $session->id])->json('shift.id');
    $this->withToken($token)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 7000])->assertOk();
    $this->cash->receiveVault(actorFor($this->cashier), $session->fresh(), null, 7000);
    $short = CashVariance::sole();
    expect($short->amount_minor)->toBe(-3000);

    $employee = app(HrService::class)->saveEmployee(actorFor($this->owner), new Employee, ['name' => 'Esi Teller', 'branch_id' => $this->branches['HQ']->id,
        'hire_date' => now()->startOfYear()->toDateString(), 'employment_type' => 'permanent', 'pay_method' => 'cash', 'user_id' => $this->teller->id, 'basic_salary_minor' => 200000]);
    $this->cash->decide(actorFor($this->controller), $short, 'charge_staff', 'Shortage agreed with the teller', months: 3);
    $loan = EmployeeLoan::sole();
    expect($loan->employee_id)->toBe($employee->id)->and($loan->kind)->toBe('shortage')->and($loan->principal_minor)->toBe(3000)->and($loan->monthly_minor)->toBe(1000);

    app(AutoPoster::class)->run($this->tenant->id);
    expect(cashBalance('cash_differences'))->toBe(0)->and(cashBalance('staff_loans'))->toBe(3000);

    expect(fn () => CashMovement::query()->update(['amount_minor' => 1]))->toThrow(QueryException::class);
});

it('lets the cashier cancel an unused float, and keeps the old simple close when cash control is off', function () {
    $session = $this->cash->issueFloat(actorFor($this->cashier), $this->vault, $this->teller, 15000);
    $this->cash->cancelIssue(actorFor($this->cashier), $session, 'Teller called in sick');
    expect($session->fresh()->status)->toBe('cancelled')->and(CashMovement::where('kind', 'float_return')->value('amount_minor'))->toBe(15000);

    app(Settings::class)->set(actorFor($this->owner), 'cash.vaults', false);
    $token = tellerToken($this);
    $this->withToken($token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 5000])->assertCreated();
    $closed = $this->withToken($token)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 5000])->assertOk()->json('shift');
    expect($closed['expected_cash_minor'])->toBe(5000)->and(VaultSession::where('status', 'open')->exists())->toBeFalse();
});

it('shows the cash desk to the cashier and income control to the income controller, and works through their screens', function () {
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    $this->actingAs(withMfa($this->teller), 'web')->get(CashDesk::getUrl())->assertForbidden();

    $this->flushSession();
    app('auth')->forgetGuards();
    $this->actingAs(withMfa($this->cashier), 'web')->get(CashDesk::getUrl())->assertOk()->assertSee($this->vault->name)->assertSee('Issue float');
    Livewire\Livewire::test(CashDesk::class)
        ->callAction(TestAction::make('issue')->arguments(['vault' => $this->vault->id]), ['teller' => $this->teller->id, 'float' => '150.00'])
        ->assertHasNoActionErrors();
    $session = VaultSession::sole();
    expect($session->float_minor)->toBe(15000)->and($session->teller_id)->toBe($this->teller->id);

    // The teller works the shift on the till; the cashier then receives the tray through the screen.
    $token = tellerToken($this);
    $this->withToken($token)->postJson('/api/v1/pos/shifts/open', ['opening_float_minor' => 0, 'vault_session_id' => $session->id])->assertCreated();
    $this->withToken($token)->postJson('/api/v1/pos/shifts/close', ['counted_cash_minor' => 0, 'denoms' => ['n100' => 1, 'n50' => 1]])->assertOk();
    $this->flushSession();
    app('auth')->forgetGuards();
    $this->actingAs(withMfa($this->cashier->fresh()), 'web');
    Livewire\Livewire::test(CashDesk::class)
        ->callAction(TestAction::make('receive')->arguments(['session' => $session->id]), ['denoms' => ['n100' => 1, 'n50' => 1]])
        ->assertNotified('Vault received');
    Livewire\Livewire::test(CashDesk::class)->callAction('handOver', ['sessions' => [$session->id]])->assertHasNoActionErrors();
    $handover = CashHandover::sole();

    $this->flushSession();
    app('auth')->forgetGuards();
    $this->actingAs(withMfa($this->controller), 'web')->get(IncomeControl::getUrl())->assertOk()->assertSee($handover->number);
    Livewire\Livewire::test(IncomeControl::class)
        ->callAction(TestAction::make('receive')->arguments(['handover' => $handover->id]), ['denoms' => ['n100' => 1, 'n50' => 1]])
        ->assertNotified('Handover received');
    expect($handover->fresh()->status)->toBe('received')->and(CashVariance::count())->toBe(0);

    // Shifts and vaults: an Evening shift gives every till a third vault.
    $this->flushSession();
    app('auth')->forgetGuards();
    $this->actingAs(withMfa($this->owner), 'web')->get(CashSetup::getUrl())->assertOk()->assertSee('Morning');
    Livewire\Livewire::test(CashSetup::class)->callAction('template', ['name' => 'Evening', 'starts_at' => '18:00', 'ends_at' => '23:00', 'sort' => 3, 'is_active' => true])
        ->assertHasNoActionErrors();
    expect(Vault::where('till_id', $this->till->id)->count())->toBe(3);
});
