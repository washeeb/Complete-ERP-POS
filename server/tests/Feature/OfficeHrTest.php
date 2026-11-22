<?php

use App\Domain\Hr\HrService;
use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Till;
use App\Security\ScopeType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Employees, Timesheets, Leave, Payroll, Loans & advances and My leave & payslips in the new back office (part 21).

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->b = setUpBranches($this->owner);
    $this->hq = $this->b['HQ'];
    $till = Till::where('branch_id', $this->hq->id)->first();
    $this->cashier = withMfa(staff($this->owner, 'esi', 2, 'teller', ScopeType::Till, $till->id));
    $this->hrm = withMfa(staff($this->owner, 'abena', 4, 'hr_manager'));
    $this->officer = withMfa(staff($this->owner, 'yaa', 3, 'payroll_officer'));
    $this->fm = withMfa(staff($this->owner, 'kwame', 5, 'finance_manager'));
    $this->bm = withMfa(staff($this->owner, 'kofi', 4, 'branch_manager', ScopeType::Branch, $this->hq->id));
});

it('adds an employee, changes them, ends their employment and keeps pay details to HR', function () {
    $this->actingAs($this->hrm, 'web');
    $form = $this->getJson('/office-api/employees/form')->assertOk()->json();
    expect($form['values'])->toMatchArray(['employment_type' => 'permanent', 'pay_method' => 'bank', 'overtime_rate' => '1.5'])->and($form['users'])->not->toBeEmpty();
    $base = ['name' => 'Esi Cashier', 'branch_id' => $this->hq->id, 'employment_type' => 'permanent', 'hire_date' => '2026-01-05', 'basic_salary' => '2000', 'pay_method' => 'bank'];
    $this->postJson('/office-api/employees', $base)->assertStatus(422); // a bank account is needed
    $id = $this->postJson('/office-api/employees', $base + ['bank_name' => 'GCB', 'bank_account_no' => '1020304050', 'transport_allowance' => '200', 'user_id' => $this->cashier->id,
        'tier3_pct' => '5', 'overtime_junior' => true])->assertOk()->json('id');
    $e = Employee::find($id);
    expect($e)->basic_salary_minor->toBe(200000)->transport_allowance_minor->toBe(20000)->tier3_bp->toBe(500)->overtime_junior->toBeTrue()->number->toStartWith('EMP-');
    $one = $this->getJson("/office-api/employees/{$id}/form")->assertOk()->json();
    expect($one['values'])->toMatchArray(['basic_salary' => '2000.00', 'bank_account_no' => '1020304050', 'tier3_pct' => '5']);
    $this->postJson("/office-api/employees/{$id}", array_merge($one['values'], ['pay_method' => 'momo', 'momo_number' => '0244000000', 'basic_salary' => '2100']))->assertOk();
    expect(Employee::find($id))->basic_salary_minor->toBe(210000)->pay_method->toBe('momo')->bank_account_no->toBe('1020304050'); // kept, as the current form
    expect($this->getJson('/office-api/employees?q=esi')->json('rows.0'))->toMatchArray(['name' => 'Esi Cashier', 'status' => 'active']);

    $this->postJson("/office-api/employees/{$id}/action", ['action' => 'terminate', 'date' => '2026-11-30'])->assertStatus(422);
    $this->postJson("/office-api/employees/{$id}/action", ['action' => 'terminate', 'date' => '2026-11-30', 'reason' => 'Resigned'])->assertOk();
    expect(Employee::find($id)->status)->toBe('terminated')
        ->and($this->getJson('/office-api/employees')->json('total'))->toBe(0)
        ->and($this->getJson('/office-api/employees?status=terminated')->json('total'))->toBe(1);

    // A branch manager may look at the list but not open pay details; a teller sees none of it.
    $this->actingAs($this->bm, 'web');
    if ($this->getJson('/office-api/employees')->status() === 200) {
        $this->getJson("/office-api/employees/{$id}/form")->assertForbidden();
    }
    $this->actingAs($this->cashier, 'web');
    $this->getJson('/office-api/employees')->assertForbidden();
});

it('records, approves, refuses and cancels leave, and gives the person their own page', function () {
    Storage::fake('local');
    $hr = app(HrService::class);
    $esi = $hr->saveEmployee(actorFor($this->hrm), new Employee, ['name' => 'Esi Cashier', 'branch_id' => $this->hq->id, 'hire_date' => '2026-01-01', 'employment_type' => 'permanent',
        'pay_method' => 'cash', 'basic_salary_minor' => 200000, 'user_id' => $this->cashier->id]);
    $annual = LeaveType::where('code', 'ANNUAL')->sole();
    $mon = Carbon::now()->addWeeks(2)->startOfWeek();

    $this->actingAs($this->hrm, 'web');
    $form = $this->getJson('/office-api/leave/form')->assertOk()->json();
    expect(collect($form['employees'])->pluck('id'))->toContain($esi->id);
    $id = $this->post('/office-api/leave', ['employee_id' => $esi->id, 'leave_type_id' => $annual->id, 'starts_on' => $mon->toDateString(), 'ends_on' => $mon->copy()->addDays(2)->toDateString(),
        'reason' => 'Family', 'document' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertOk()->json('id');
    $r = LeaveRequest::find($id);
    expect($r->days)->toBe(3)->and($r->document_path)->toStartWith('leave-documents/');

    $this->actingAs($this->bm, 'web');
    $list = $this->getJson('/office-api/leave?status=requested')->assertOk()->json();
    expect($list['waiting'])->toBe(1)->and($list['rows'][0])->toMatchArray(['employee' => 'Esi Cashier', 'days' => 3, 'can_decide' => true, 'document' => true]);
    $this->postJson("/office-api/leave/{$id}/action", ['action' => 'refuse'])->assertStatus(422); // a reason is needed
    $this->postJson("/office-api/leave/{$id}/action", ['action' => 'approve'])->assertOk();
    expect($r->fresh()->status)->toBe('approved');

    // Esi's own page: balances, the request, and asking for more; she cancels her own.
    $this->actingAs($this->cashier, 'web');
    $mine = $this->getJson('/office-api/my-hr')->assertOk()->json();
    expect($mine['name'])->toBe('Esi Cashier')->and(collect($mine['balances'])->firstWhere('name', $annual->name)['left'])->toBe($annual->days_per_year - 3)
        ->and($mine['requests'][0])->toMatchArray(['status' => 'approved', 'can_cancel' => true, 'can_decide' => false]);
    $two = $this->post('/office-api/leave', ['mine' => '1', 'leave_type_id' => $annual->id, 'starts_on' => $mon->copy()->addWeek()->toDateString(),
        'ends_on' => $mon->copy()->addWeek()->toDateString()], ['Accept' => 'application/json'])->assertOk()->json('id');
    expect(LeaveRequest::find($two)->employee_id)->toBe($esi->id);
    $this->postJson("/office-api/leave/{$two}/action", ['mine' => true, 'action' => 'approve'])->assertStatus(422); // only cancel her own
    $this->postJson("/office-api/leave/{$two}/action", ['mine' => true, 'action' => 'cancel'])->assertOk();
    expect(LeaveRequest::find($two)->status)->toBe('cancelled');
    $this->getJson('/office-api/leave')->assertForbidden();
});

it('gives an advance, runs payroll through approve and pay, and fixes a timesheet', function () {
    $hr = app(HrService::class);
    $y = now()->year + 1;
    $esi = $hr->saveEmployee(actorFor($this->hrm), new Employee, ['name' => 'Esi Cashier', 'branch_id' => $this->hq->id, 'hire_date' => "{$y}-01-01", 'employment_type' => 'permanent',
        'pay_method' => 'cash', 'basic_salary_minor' => 200000, 'user_id' => $this->cashier->id]);

    $this->actingAs($this->hrm, 'web');
    $loans = $this->getJson('/office-api/loans')->assertOk()->json();
    expect($loans['can_issue'])->toBeTrue()->and(collect($loans['form']['employees'])->pluck('id'))->toContain($esi->id);
    $this->postJson('/office-api/loans', ['employee_id' => $esi->id, 'kind' => 'advance', 'amount' => '300', 'monthly' => '150', 'first_month' => "{$y}-03-01",
        'account_id' => $loans['form']['account'], 'issued_on' => now()->toDateString()])->assertOk();
    expect(EmployeeLoan::sole()->principal_minor)->toBe(30000)
        ->and($this->getJson('/office-api/loans?q=esi')->json('rows.0'))->toMatchArray(['amount' => 30000, 'monthly' => 15000, 'owed' => 30000]);

    $this->actingAs($this->officer, 'web');
    expect($this->getJson('/office-api/payroll')->assertOk()->json('can_prepare'))->toBeTrue();
    $id = $this->postJson('/office-api/payroll', ['month' => 3, 'year' => $y])->assertOk()->json('id');
    $run = $this->getJson("/office-api/payroll/{$id}")->assertOk()->json();
    expect($run)->toMatchArray(['status' => 'draft', 'month' => "March {$y}"])->and($run['payslips'][0]['employee'])->toBe('Esi Cashier')
        ->and($run['actions'])->toMatchArray(['recalculate' => true, 'approve' => false, 'pay' => null])
        ->and($run['print']['payslips'])->toBe("/print/payroll/{$id}/payslips");
    $this->postJson("/office-api/payroll/{$id}/action", ['action' => 'recalculate'])->assertOk();
    $this->postJson("/office-api/payroll/{$id}/action", ['action' => 'approve'])->assertForbidden(); // a payroll officer prepares; someone else approves

    $this->actingAs($this->fm, 'web');
    $ok = $this->postJson("/office-api/payroll/{$id}/action", ['action' => 'approve'])->assertOk()->json('run');
    expect($ok['status'])->toBe('approved')->and($ok['actions']['pay'])->not->toBeNull();
    $paid = $this->postJson("/office-api/payroll/{$id}/action", ['action' => 'pay', 'bank_account_id' => $ok['actions']['pay']['bank'],
        'cash_account_id' => $ok['actions']['pay']['cash'], 'paid_on' => now()->toDateString()])->assertOk()->json('run');
    expect($paid['status'])->toBe('paid')->and(PayrollRun::find($id)->status)->toBe('paid');

    // Timesheets: the branch manager adds a missing clock-out (with the reason) and cancels a mistake.
    $this->actingAs($this->bm, 'web');
    $ts = $this->getJson('/office-api/timesheets?person='.$this->cashier->id)->assertOk()->json();
    expect($ts['branch'])->toBe($this->hq->id)->and(collect($ts['rows'])->pluck('name'))->toContain('Esi Cashier');
    $body = ['action' => 'add', 'branch' => $this->hq->id, 'person' => $this->cashier->id, 'kind' => 'in', 'at' => now()->subHours(3)->format('Y-m-d\TH:i')];
    $this->postJson('/office-api/timesheets/clock', $body)->assertStatus(422); // the reason is needed
    $this->postJson('/office-api/timesheets/clock', $body + ['reason' => 'Forgot to clock in'])->assertOk();
    $event = $this->getJson('/office-api/timesheets?person='.$this->cashier->id)->json('events.0');
    expect($event)->toMatchArray(['kind' => 'in', 'voided' => false]);
    $this->postJson('/office-api/timesheets/clock', ['action' => 'void', 'branch' => $this->hq->id, 'person' => $this->cashier->id, 'event' => $event['id'], 'reason' => 'Wrong day'])->assertOk();
    expect($this->getJson('/office-api/timesheets?person='.$this->cashier->id)->json('events.0.voided'))->toBeTrue();
    expect(DB::table('attendance_events')->where('user_id', $this->cashier->id)->count())->toBe(2);
});
