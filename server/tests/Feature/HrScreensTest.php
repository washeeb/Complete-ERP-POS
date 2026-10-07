<?php

use App\Domain\Hr\HrService;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\PayrollProfilePage;
use App\Filament\Pages\Timesheets;
use App\Filament\Resources\EmployeeLoans\EmployeeLoanResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\LeaveRequests\Pages\ListLeaveRequests;
use App\Filament\Resources\PayrollRuns\Pages\ListPayrollRuns;
use App\Filament\Resources\PayrollRuns\Pages\ViewPayrollRun;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollProfile;
use App\Models\PayrollRun;
use App\Security\ScopeType;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    [$this->tenant, $this->owner] = installCompany();
    $this->branches = setUpBranches($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->hrm = withMfa(staff($this->owner, 'abena', 4, 'hr_manager'));
    $this->fm = withMfa(staff($this->owner, 'kwame', 5, 'finance_manager'));
    $this->actingAs($this->hrm);
});

it('adds an employee and prepares, approves and shows the payroll on screen', function () {
    Livewire::test(CreateEmployee::class)->fillForm(['name' => 'Akosua Mensah', 'branch_id' => $this->branches['HQ']->id, 'employment_type' => 'permanent',
        'hire_date' => now()->startOfMonth()->toDateString(), 'basic_salary' => '2500', 'transport_allowance' => '200', 'overtime_rate' => '1.5', 'tier3_pct' => '0',
        'pay_method' => 'momo', 'momo_number' => '0244000111', 'ssnit_no' => 'C123456789012'])->call('create')->assertHasNoFormErrors();
    $e = Employee::sole();
    expect($e->basic_salary_minor)->toBe(250000)->and($e->transport_allowance_minor)->toBe(20000)->and($e->overtime_rate_bp)->toBe(15000);
    $this->get(EmployeeResource::getUrl())->assertOk()->assertSee('Akosua Mensah');

    Livewire::test(ListPayrollRuns::class)->callAction('prepare', ['month' => now()->month, 'year' => now()->year, 'pay_date' => now()->endOfMonth()->toDateString()])
        ->assertNotified('Payroll calculated');
    $run = PayrollRun::sole();
    $this->get(PayrollRunResource::getUrl('view', ['record' => $run]))->assertOk()->assertSee('Akosua Mensah')->assertSee('NOT YET CHECKED BY AN ACCOUNTANT');

    $this->flushSession();
    $this->actingAs($this->fm);
    Livewire::test(ViewPayrollRun::class, ['record' => $run->id])->callAction('approve')->assertNotified('Payroll approved');
    expect($run->fresh()->status)->toBe('approved');
    $this->get(route('print.payslips', $run))->assertOk()->assertSee('Akosua Mensah')->assertSee('NET PAY');
    $this->get(route('print.pay-sheet', $run))->assertOk()->assertSee('0244000111');

    // The accountant (finance manager) checks the rules and confirms them.
    Livewire::test(PayrollProfilePage::class)->assertSee('NOT YET CHECKED')->callAction('verify')->assertNotified('Rules confirmed');
    expect(PayrollProfile::sole()->verified_by_name)->toBe('Kwame');
});

it('lets staff request leave and see payslips themselves, and managers approve it', function () {
    $staff = withMfa(staff($this->owner, 'esi', 2, 'cashier', ScopeType::Branch, $this->branches['HQ']->id));
    app(HrService::class)->saveEmployee(actorFor($this->hrm), new Employee, ['name' => 'Esi', 'user_id' => $staff->id, 'branch_id' => $this->branches['HQ']->id,
        'hire_date' => now()->subYear()->toDateString(), 'pay_method' => 'cash', 'employment_type' => 'permanent', 'basic_salary_minor' => 150000]);
    $this->flushSession();
    $this->actingAs($staff);
    $mon = now()->addWeeks(2)->startOfWeek();
    Livewire::test(MyHr::class)->assertSee('Annual leave')->assertSee('of 15 days')
        ->callAction('request', ['leave_type_id' => LeaveType::where('code', 'ANNUAL')->value('id'), 'starts_on' => $mon->toDateString(), 'ends_on' => $mon->copy()->addDays(1)->toDateString()])
        ->assertNotified('Leave requested')->assertSee('of 15 days');
    expect(LeaveRequest::sole()->days)->toBe(2);
    $this->get(EmployeeResource::getUrl())->assertForbidden();

    $this->flushSession();
    $this->actingAs(withMfa(staff($this->owner, 'kofi', 4, 'branch_manager', ScopeType::Branch, $this->branches['HQ']->id)));
    $this->get(LeaveRequestResource::getUrl())->assertOk()->assertSee('Esi');
    Livewire::test(ListLeaveRequests::class)->callTableAction('approve', LeaveRequest::sole())->assertNotified('Leave approved');
    expect(LeaveRequest::sole()->status)->toBe('approved');
    $this->get(Timesheets::getUrl())->assertOk()->assertSee('Esi');
});

it('keeps HR and payroll from people without access, including IT admin', function () {
    foreach ([staff($this->owner, 'it', 4, 'it_admin'), staff($this->owner, 'cash', 2, 'cashier')] as $u) {
        $this->flushSession();
        $this->actingAs(withMfa($u));
        $this->get(EmployeeResource::getUrl())->assertForbidden();
        $this->get(PayrollRunResource::getUrl())->assertForbidden();
        $this->get(EmployeeLoanResource::getUrl())->assertForbidden();
    }
});
