<?php

use App\Domain\Access\AccessService;
use App\Domain\Finance\AutoPoster;
use App\Domain\Finance\GlCheck;
use App\Domain\Hr\AttendanceService;
use App\Domain\Hr\HrService;
use App\Domain\Hr\PayrollService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\DomainException;
use App\Models\Account;
use App\Models\Employee;
use App\Models\LeaveType;
use App\Models\PayrollProfile;
use App\Models\Till;
use App\Security\ScopeType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    $this->hq = $this->branches['HQ'];
    $this->till = Till::where('branch_id', $this->hq->id)->first();
    $this->cashier = staff($this->owner, 'esi', 2, 'cashier', ScopeType::Till, $this->till->id);
    app(AccessService::class)->setPin(actorFor($this->owner), $this->cashier, '482613');
    $this->hrm = staff($this->owner, 'abena', 4, 'hr_manager');
    $this->officer = staff($this->owner, 'yaa', 3, 'payroll_officer');
    $this->fm = staff($this->owner, 'kwame', 5, 'finance_manager');
    $this->bm = staff($this->owner, 'kofi', 4, 'branch_manager', ScopeType::Branch, $this->hq->id);
    $this->hr = app(HrService::class);
    $this->y = now()->year + 1; // a month after everything set up here, independent of today's date
    $mk = fn (string $name, array $extra) => $this->hr->saveEmployee(actorFor($this->hrm), new Employee, $extra + ['name' => $name, 'branch_id' => $this->hq->id,
        'hire_date' => "{$this->y}-01-01", 'employment_type' => 'permanent', 'pay_method' => 'cash']);
    $this->esi = $mk('Esi Cashier', ['user_id' => $this->cashier->id, 'basic_salary_minor' => 200000, 'transport_allowance_minor' => 20000, 'overtime_junior' => true]);
    $this->kofi = $mk('Kofi Manager', ['user_id' => $this->bm->id, 'basic_salary_minor' => 600000, 'pay_method' => 'bank', 'bank_name' => 'GCB', 'bank_account_no' => '1020304050',
        'tier3_bp' => 500, 'welfare_minor' => 2000]);
});

it('runs a month of payroll from clock-ins, leave and an advance to payslips and the books (done-when)', function () {
    $y = $this->y;
    expect(PayrollProfile::sole()->code)->toBe('GH-2024')->and(LeaveType::count())->toBe(5)->and($this->esi->number)->toStartWith('EMP-');

    // Clocking on the till: online with the PIN, and one shift recorded offline and sent later with the offline sign-in.
    Carbon::setTestNow(Carbon::create($y, 3, 2, 7, 0, 0, 'UTC'));
    $ft = fakeTill($this, $this->till, 'esi', '482613');
    $clock = fn (string $kind) => $this->withToken($ft->token)->postJson('/api/v1/pos/clock', ['id' => (string) Str::uuid7(), 'username' => 'esi', 'pin' => '482613', 'kind' => $kind]);
    $clock('in')->assertOk()->assertJson(['name' => 'Esi', 'kind' => 'in', 'status' => 'accepted']);
    Carbon::setTestNow(Carbon::create($y, 3, 2, 18, 30, 0, 'UTC')); // 11.5 h: 3.5 h overtime
    $clock('out')->assertOk();
    $this->withToken($ft->token)->postJson('/api/v1/pos/clock', ['id' => (string) Str::uuid7(), 'username' => 'esi', 'pin' => '000000', 'kind' => 'in'])->assertUnauthorized();
    Carbon::setTestNow(Carbon::create($y, 3, 4, 20, 0, 0, 'UTC'));
    app('auth')->forgetGuards();
    $r = $ft->sync([
        ['id' => (string) Str::uuid7(), 'type' => 'clock', 'grant' => $ft->grant, 'payload' => ['kind' => 'in', 'occurred_at' => "{$y}-03-03T08:00:00Z"]],
        ['id' => (string) Str::uuid7(), 'type' => 'clock', 'grant' => $ft->grant, 'payload' => ['kind' => 'out', 'occurred_at' => "{$y}-03-03T16:00:00Z"]],
    ])->assertOk()->json('results');
    expect(collect($r)->pluck('status')->all())->toBe(['accepted', 'accepted']);
    $sheet = AttendanceService::timesheet($this->cashier->id, "{$y}-03-01", "{$y}-03-31");
    expect($sheet['total_centi'])->toBe(1950)->and($sheet['overtime_centi'])->toBe(350)->and(count($sheet['days']))->toBe(2);

    // Leave: Esi asks for 3 days annual and 2 days unpaid; the branch manager approves; Kofi cannot approve his own.
    $annual = LeaveType::where('code', 'ANNUAL')->sole();
    $unpaid = LeaveType::where('code', 'UNPAID')->sole();
    $mon = Carbon::create($y, 3, 7)->next(Carbon::MONDAY); // Mon–Wed annual, Thu–Fri unpaid
    $d = fn (int $plus) => $mon->copy()->addDays($plus)->toDateString();
    $l1 = $this->hr->requestLeave(actorFor($this->cashier), $this->esi, $annual, $d(0), $d(2), 'Family visit');
    $l2 = $this->hr->requestLeave(actorFor($this->cashier), $this->esi, $unpaid, $d(3), $d(4));
    expect($l1->days)->toBe(3)
        ->and(fn () => $this->hr->requestLeave(actorFor($this->cashier), $this->esi, $annual, $d(2), $d(2)))->toThrow(DomainException::class, 'overlap')
        ->and(fn () => $this->hr->requestLeave(actorFor($this->cashier), $this->kofi, $annual, "{$y}-04-01", "{$y}-04-01"))->toThrow(AccessDenied::class);
    $this->hr->decideLeave(actorFor($this->bm), $l1, true);
    $this->hr->decideLeave(actorFor($this->bm), $l2, true);
    $own = $this->hr->requestLeave(actorFor($this->bm), $this->kofi, $annual, "{$y}-04-06", "{$y}-04-07");
    expect(fn () => $this->hr->decideLeave(actorFor($this->bm), $own, true))->toThrow(DomainException::class, 'your own')
        ->and(HrService::leaveBalance($this->esi, $annual, $y))->toBe(12);

    // A salary advance of GHS 300, GHS 150 a month, paid from the office cash.
    $this->hr->issueLoan(actorFor($this->hrm), $this->esi, 'advance', 30000, 15000, "{$y}-03-01", Account::where('system_key', 'cash_office')->sole(), "{$y}-03-04");

    // Payroll: prepared by the payroll officer, approved by finance (not the preparer), paid from bank and cash.
    Carbon::setTestNow(Carbon::create($y, 3, 31, 12));
    $pay = app(PayrollService::class);
    $run = $pay->prepare(actorFor($this->officer), $y, 3);
    $esi = $run->payslips()->where('employee_id', $this->esi->id)->sole();
    $working = HrService::workingDays("{$y}-03-01", "{$y}-03-31");
    expect($run->status)->toBe('draft')->and($run->payslips()->count())->toBe(2)
        ->and($esi->unpaid_leave_days)->toBe(2)->and($esi->overtime_hours)->toBe(3.5)->and($esi->loans_minor)->toBe(15000)
        ->and($esi->basic_minor)->toBe(200000 - intdiv(200000 * 2 * 2 + $working, 2 * $working));
    expect(fn () => $pay->approve(actorFor($this->officer), $run))->toThrow(AccessDenied::class);
    $pay->approve(actorFor($this->fm), $run);
    expect(fn () => $pay->prepare(actorFor($this->officer), $y, 3))->toThrow(DomainException::class, 'already approved')
        ->and(DB::table('loan_repayments')->sum('amount_minor'))->toEqual(15000);
    $pay->pay(actorFor($this->fm), $run->fresh(), Account::where('system_key', 'bank')->sole(), Account::where('system_key', 'cash_office')->sole(), "{$y}-03-31");

    app(AutoPoster::class)->run($this->tenant->id);
    $bal = fn (string $key) => (int) DB::table('journal_lines')->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')->where('accounts.system_key', $key)->sum('amount_minor');
    $t = $run->fresh()->totals;
    expect(app(GlCheck::class)->run($this->tenant->id)['ok'])->toBeTrue()
        ->and($bal('net_pay'))->toBe(0)
        ->and($bal('salaries'))->toBe($t['gross_minor'])
        ->and($bal('paye'))->toBe(-$t['paye_minor'])
        ->and($bal('ssnit') + $bal('tier2'))->toBe(-($t['ssnit_employee_minor'] + $t['ssnit_employer_minor']))
        ->and($bal('staff_loans'))->toBe(30000 - 15000)
        ->and($bal('bank'))->toBe(-$run->payslips()->where('employee_id', $this->kofi->id)->value('net_minor'));

    // Payslips: each person sees their own; nobody else's.
    $this->actingAs($this->cashier)->get(route('print.payslip', $esi))->assertOk()->assertSee('NET PAY')->assertSee('Esi Cashier');
    $this->get(route('print.payslip', $run->payslips()->where('employee_id', $this->kofi->id)->sole()))->assertForbidden();
    $this->flushSession();
    expect($this->actingAs($this->fm)->get(route('print.pay-list', $run))->assertOk()->streamedContent())->toContain('1020304050')->not->toContain('Esi Cashier');
    Carbon::setTestNow();
});

it('lets a manager correct the clock with a reason, and the events stay on record', function () {
    $svc = app(AttendanceService::class);
    $svc->addManual(actorFor($this->bm), $this->cashier, $this->hq, 'in', now()->subHours(9)->toDateTimeString(), 'Forgot to clock in');
    $svc->addManual(actorFor($this->bm), $this->cashier, $this->hq, 'out', now()->subHour()->toDateTimeString(), 'Forgot to clock out');
    $wrong = DB::table('attendance_events')->where('kind', 'out')->value('id');
    $svc->void(actorFor($this->bm), $wrong, 'Left at 5');
    expect(fn () => $svc->void(actorFor($this->bm), $wrong, 'again'))->toThrow(DomainException::class, 'Already')
        ->and(fn () => $svc->addManual(actorFor($this->cashier), $this->cashier, $this->hq, 'out', now()->toDateTimeString(), 'me'))->toThrow(AccessDenied::class)
        ->and(fn () => DB::transaction(fn () => DB::table('attendance_events')->delete()))->toThrow(Exception::class, 'append-only');
    expect(AttendanceService::timesheet($this->cashier->id, now()->subDay()->toDateString(), now()->toDateString())['total_centi'])->toBe(0)
        ->and(DB::table('attendance_events')->count())->toBe(3);
});
