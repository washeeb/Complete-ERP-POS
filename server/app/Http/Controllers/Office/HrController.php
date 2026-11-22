<?php

namespace App\Http\Controllers\Office;

use App\Domain\Hr\AttendanceService;
use App\Domain\Hr\HrService;
use App\Domain\Hr\PayrollService;
use App\Domain\Office\HrScreen;
use App\Domain\Org\ArchiveService;
use App\Domain\Support\Actor;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Office\Concerns\ActsOnAccess;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * HR & payroll in the new back office (App\Domain\Office\HrScreen): Employees (save, end employment, archive),
 * Timesheets (add a missing clock event, cancel one), Leave (approve, refuse, cancel, record for someone), Payroll
 * (prepare, recalculate, approve, record payment), Loans & advances (give one) and My leave & payslips (request, cancel)
 * — each through the same service as the current pages.
 */
class HrController extends Controller
{
    use ActsOnAccess;

    private function screen(Request $r, bool $can): HrScreen
    {
        $this->allowed($can);

        return new HrScreen($r->user());
    }

    // -------------------------------------------------------------------------------------------- Employees

    public function employees(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, HrScreen::canEmployees())->employees($request->query()));
    }

    public function employeeForm(Request $request, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, HrScreen::canEmployees() && Erp::canAnywhere('hr.manage'));

        return response()->json($screen->employeeForm($id ? $screen->employeesQuery()->findOrFail($id) : null));
    }

    public function employeeSave(Request $request, HrService $hr, ?string $id = null): JsonResponse
    {
        $screen = $this->screen($request, $id ? Erp::canAnywhere('hr.manage') : EmployeeResource::canCreate());
        $money = ['nullable', 'numeric', 'min:0'];
        $d = $request->validate(['name' => ['required', 'string', 'max:255'], 'gender' => ['nullable', 'in:female,male'], 'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string', 'max:255'],
            'ghana_card_no' => ['nullable', 'string', 'max:30'], 'ssnit_no' => ['nullable', 'string', 'max:30'], 'user_id' => ['nullable', 'uuid'],
            'branch_id' => ['required', Rule::in(array_keys(FinanceUi::branches()))], 'department_id' => ['nullable', 'uuid'], 'job_title' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['required', Rule::in(array_keys(Employee::TYPES))], 'hire_date' => ['required', 'date_format:Y-m-d'], 'contract_end' => ['nullable', 'date_format:Y-m-d'],
            'basic_salary' => ['required', 'numeric', 'min:0'], 'transport_allowance' => $money, 'meal_allowance' => $money, 'welfare' => $money,
            'overtime_rate' => ['nullable', 'numeric', 'min:1'], 'overtime_junior' => ['boolean'], 'ssnit_exempt' => ['boolean'], 'tier3_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'commission_pct' => ['nullable', 'numeric', 'min:0', 'max:100'], 'takes_appointments' => ['boolean'], 'pay_method' => ['required', Rule::in(array_keys(Employee::PAY_METHODS))],
            'bank_name' => ['nullable', 'string', 'max:255'], 'bank_account_no' => ['nullable', 'string', 'max:40'], 'momo_number' => ['nullable', 'string', 'max:20'], 'notes' => ['nullable', 'string', 'max:5000']],
            ['name.*' => 'Enter the name.', 'branch_id.*' => 'Choose the branch.', 'hire_date.*' => 'Enter the start date.', 'basic_salary.*' => 'Enter the basic salary.']);
        $e = $id ? $screen->employeesQuery()->findOrFail($id) : new Employee;
        $blank = fn ($k) => ($d[$k] ?? '') === '' ? null : $d[$k];
        foreach (['gender', 'date_of_birth', 'phone', 'email', 'address', 'ghana_card_no', 'ssnit_no', 'user_id', 'department_id', 'job_title', 'contract_end', 'bank_name', 'bank_account_no', 'momo_number', 'notes'] as $k) {
            $d[$k] = $blank($k);
        }
        if ($d['pay_method'] !== 'bank') {
            [$d['bank_name'], $d['bank_account_no']] = [$e->bank_name, $e->bank_account_no]; // hidden on the current form: kept as they were
        }
        if ($d['pay_method'] !== 'momo') {
            $d['momo_number'] = $e->momo_number;
        }

        return $this->act(fn (Actor $actor) => ['id' => $hr->saveEmployee($actor, $e, EmployeeResource::toDomain($d))->id, 'message' => 'Saved.']);
    }

    public function employeeAction(Request $request, string $id, HrService $hr, ArchiveService $archive): JsonResponse
    {
        $screen = $this->screen($request, HrScreen::canEmployees());
        $e = $screen->employeesQuery()->findOrFail($id);
        $a = $request->validate(['action' => ['required', Rule::in(['terminate', 'archive', 'restore'])]])['action'];
        $d = $request->validate(match ($a) {
            'terminate' => ['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:255']],
            'archive' => ['reason' => ['required', 'string', 'min:3', 'max:255']],
            default => [],
        }, ['date.*' => 'Enter the last day.', 'reason.*' => 'Say why.']);
        $this->allowed($a === 'terminate' ? Erp::canAnywhere('hr.manage') : $archive->allowed($request->user(), $e));

        return $this->act(function (Actor $actor) use ($a, $d, $e, $hr, $archive) {
            match ($a) {
                'terminate' => $hr->terminate($actor, $e, $d['date'], $d['reason']),
                'archive' => $archive->archive($actor, $e, $d['reason']),
                default => $archive->restore($actor, $e),
            };

            return ['message' => ['terminate' => 'Employment ended.', 'archive' => 'Archived.', 'restore' => 'Restored.'][$a]];
        });
    }

    // -------------------------------------------------------------------------------------------- Timesheets

    public function timesheets(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, HrScreen::canTimesheets())->timesheets($request->query()));
    }

    /** add (a missing clock-in or clock-out) or void (cancel one), each with the reason. */
    public function clock(Request $request, AttendanceService $svc): JsonResponse
    {
        $screen = $this->screen($request, HrScreen::canTimesheets());
        $d = $request->validate(['action' => ['required', 'in:add,void'], 'branch' => ['required', 'uuid'], 'person' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:255'],
            'kind' => ['required_if:action,add', 'nullable', 'in:in,out'], 'at' => ['required_if:action,add', 'nullable', 'date'], 'event' => ['required_if:action,void', 'nullable', 'uuid']],
            ['reason.*' => 'Give the reason (needed to add or cancel).', 'at.*' => 'Enter when.']);
        $ids = Erp::branchIds('attendance.manage');
        abort_unless($ids === null || in_array($d['branch'], $ids, true), 403);
        $branch = Branch::findOrFail($d['branch']);
        $user = User::findOrFail($d['person']);
        abort_unless(Employee::where('branch_id', $branch->id)->where('user_id', $user->id)->exists(), 404);
        $tz = Tenant::find($request->user()->tenant_id)?->timezone ?: 'UTC';

        return $this->act(function (Actor $actor) use ($svc, $d, $branch, $user, $tz) {
            $d['action'] === 'add'
                ? $svc->addManual($actor, $user, $branch, $d['kind'], Carbon::parse($d['at'], $tz)->utc()->toDateTimeString(), $d['reason'])
                : $svc->void($actor, $d['event'], $d['reason']);

            return ['message' => $d['action'] === 'add' ? 'Added.' : 'Cancelled.'];
        });
    }

    // -------------------------------------------------------------------------------------------- Leave

    public function leave(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, HrScreen::canLeave())->leave($request->query()));
    }

    public function leaveForm(Request $request): JsonResponse
    {
        $mine = $request->boolean('mine');
        $screen = $this->screen($request, $mine ? HrScreen::canMine() : Erp::canAnywhere('hr.manage'));

        return response()->json($screen->leaveForm(! $mine));
    }

    /** Request leave: for yourself (My leave), or by HR for anyone; a document (doctor's note) may go with it. */
    public function leaveRequest(Request $request, HrService $hr): JsonResponse
    {
        $mine = $request->boolean('mine');
        $screen = $this->screen($request, $mine ? HrScreen::canMine() : Erp::canAnywhere('hr.manage'));
        $d = $request->validate(['employee_id' => [$mine ? 'nullable' : 'required', 'uuid'], 'leave_type_id' => ['required', 'uuid'], 'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['nullable', 'string', 'max:255'], 'document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']],
            ['employee_id.*' => 'Choose the employee.', 'leave_type_id.*' => 'Choose the type of leave.', 'starts_on.*' => 'Enter the first day off.', 'ends_on.*' => 'Enter the last day off.',
                'document.*' => 'The document is a photo or PDF up to 5 MB.']);
        $e = $mine ? $screen->me() : Employee::findOrFail($d['employee_id']);
        $type = LeaveType::findOrFail($d['leave_type_id']);
        $path = $request->file('document')?->store('leave-documents', 'local');

        return $this->act(fn (Actor $actor) => ['id' => $hr->requestLeave($actor, $e, $type, $d['starts_on'], $d['ends_on'], ($d['reason'] ?? '') ?: null, $path)->id,
            'message' => 'Leave requested.']);
    }

    /** approve · refuse (reason) · cancel, as the current list's buttons; a person cancels their own from My leave. */
    public function leaveAction(Request $request, string $id, HrService $hr): JsonResponse
    {
        $mine = $request->boolean('mine');
        $screen = $this->screen($request, $mine ? HrScreen::canMine() : HrScreen::canLeave());
        $r = $mine ? LeaveRequest::where('employee_id', $screen->me()->id)->findOrFail($id) : $screen->leaveQuery()->findOrFail($id);
        $a = $request->validate(['action' => ['required', Rule::in($mine ? ['cancel'] : ['approve', 'refuse', 'cancel'])]])['action'];
        $note = $a === 'refuse' ? $request->validate(['note' => ['required', 'string', 'max:255']], ['note.*' => 'Give the reason.'])['note'] : null;

        return $this->act(function (Actor $actor) use ($a, $r, $hr, $note) {
            match ($a) {
                'approve' => $hr->decideLeave($actor, $r, true),
                'refuse' => $hr->decideLeave($actor, $r, false, $note),
                default => $hr->cancelLeave($actor, $r),
            };

            return ['message' => ['approve' => 'Leave approved.', 'refuse' => 'Leave refused.', 'cancel' => 'Leave cancelled.'][$a]];
        });
    }

    // -------------------------------------------------------------------------------------------- Payroll

    public function runs(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, HrScreen::canPayroll())->runs($request->query()));
    }

    public function runShow(Request $request, string $id): JsonResponse
    {
        $screen = $this->screen($request, HrScreen::canPayroll());

        return response()->json($screen->run(PayrollRun::findOrFail($id)));
    }

    public function prepare(Request $request, PayrollService $svc): JsonResponse
    {
        $this->screen($request, HrScreen::canPayroll() && Erp::canAnywhere('payroll.prepare'));
        $d = $request->validate(['month' => ['required', 'integer', 'min:1', 'max:12'], 'year' => ['required', 'integer', 'min:2000', 'max:2100'], 'pay_date' => ['nullable', 'date_format:Y-m-d']],
            ['month.*' => 'Choose the month.', 'year.*' => 'Enter the year.']);

        return $this->act(fn (Actor $actor) => ['id' => $svc->prepare($actor, (int) $d['year'], (int) $d['month'], ($d['pay_date'] ?? '') ?: null)->id, 'message' => 'Payroll calculated.']);
    }

    /** recalculate · approve · pay (accounts, date), as the current page's buttons. */
    public function runAction(Request $request, string $id, PayrollService $svc): JsonResponse
    {
        $screen = $this->screen($request, HrScreen::canPayroll());
        $run = PayrollRun::findOrFail($id);
        $a = $request->validate(['action' => ['required', 'in:recalculate,approve,pay']])['action'];
        $d = $a === 'pay' ? $request->validate(['bank_account_id' => ['nullable', 'uuid'], 'cash_account_id' => ['nullable', 'uuid'], 'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today']],
            ['paid_on.*' => 'Enter the day it was paid (not in the future).']) : [];
        $this->allowed(match ($a) {
            'recalculate' => Erp::canAnywhere('payroll.prepare'), 'approve' => Erp::canAnywhere('payroll.approve'), default => Erp::canAnywhere('payroll.pay'),
        });

        return $this->act(function (Actor $actor) use ($a, $d, $run, $svc, $screen) {
            $done = match ($a) {
                'recalculate' => $svc->prepare($actor, (int) $run->year, (int) $run->month, $run->pay_date?->toDateString()),
                'approve' => $svc->approve($actor, $run),
                default => $svc->pay($actor, $run, Account::find($d['bank_account_id'] ?? null), Account::find($d['cash_account_id'] ?? null), $d['paid_on']),
            };

            return ['message' => ['recalculate' => 'Payroll calculated.', 'approve' => 'Payroll approved.', 'pay' => 'Payroll paid.'][$a], 'run' => $screen->run($done->fresh())];
        });
    }

    // -------------------------------------------------------------------------------------------- Loans & advances

    public function loans(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, HrScreen::canLoans())->loans($request->query()));
    }

    public function loanIssue(Request $request, HrService $hr): JsonResponse
    {
        $this->screen($request, HrScreen::canLoans() && Erp::canAnywhere('loans.manage'));
        $d = $request->validate(['employee_id' => ['required', 'uuid'], 'kind' => ['required', Rule::in(array_keys(EmployeeLoan::KINDS))], 'amount' => ['required', 'numeric', 'min:0.01'],
            'monthly' => ['required', 'numeric', 'min:0.01'], 'first_month' => ['required', 'date_format:Y-m-d'], 'account_id' => ['required', 'uuid'],
            'issued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['nullable', 'string', 'max:255']],
            ['employee_id.*' => 'Choose the employee.', 'amount.*' => 'Enter the amount.', 'monthly.*' => 'Enter what is deducted each month.', 'account_id.*' => 'Choose where it was paid from.',
                'issued_on.*' => 'Enter the day it was paid (not in the future).']);
        $e = Employee::findOrFail($d['employee_id']);
        $acc = Account::findOrFail($d['account_id']);

        return $this->act(fn (Actor $actor) => ['id' => $hr->issueLoan($actor, $e, $d['kind'], FinanceUi::toMinor($d['amount']), FinanceUi::toMinor($d['monthly']), $d['first_month'], $acc,
            $d['issued_on'], ($d['note'] ?? '') ?: null)->id, 'message' => 'Loan recorded.']);
    }

    // -------------------------------------------------------------------------------------------- My leave & payslips

    public function mine(Request $request): JsonResponse
    {
        return response()->json($this->screen($request, HrScreen::canMine())->mine());
    }
}
