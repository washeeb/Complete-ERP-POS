<?php

namespace App\Domain\Hr;

use App\Domain\Banking\BankingService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollProfile;
use App\Security\Authorizer;
use Illuminate\Support\Carbon;

/**
 * Employees, leave (owner decision 2026-10-14: Ghana statutory defaults, approved by the line manager) and staff
 * loans / salary advances. Salary and bank details are kept to HR and payroll.
 */
final class HrService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly DocumentNumbers $numbers) {}

    public function saveEmployee(Actor $actor, Employee $e, array $data): Employee
    {
        $this->require($actor, 'hr.manage');
        if (trim((string) ($data['name'] ?? '')) === '' || empty($data['branch_id']) || empty($data['hire_date'])) {
            throw new DomainException('Enter the name, branch and start date.');
        }
        if (! array_key_exists($data['pay_method'] ?? 'bank', Employee::PAY_METHODS) || ! array_key_exists($data['employment_type'] ?? 'permanent', Employee::TYPES)) {
            throw new DomainException('Choose the employment type and how they are paid.');
        }
        if (($data['pay_method'] ?? 'bank') === 'bank' && trim((string) ($data['bank_account_no'] ?? '')) === '') {
            throw new DomainException('Enter the bank account number (or choose MoMo or cash).');
        }
        if (($data['pay_method'] ?? '') === 'momo' && trim((string) ($data['momo_number'] ?? '')) === '') {
            throw new DomainException('Enter the mobile money number.');
        }
        foreach (['basic_salary_minor', 'transport_allowance_minor', 'meal_allowance_minor', 'welfare_minor'] as $k) {
            if ((int) ($data[$k] ?? 0) < 0) {
                throw new DomainException('Amounts cannot be negative.');
            }
        }
        if ((int) ($data['commission_bp'] ?? 0) < 0 || (int) ($data['commission_bp'] ?? 0) > 10000) {
            throw new DomainException('Commission: 0 to 100 %.');
        }
        if (! empty($data['user_id']) && Employee::where('user_id', $data['user_id'])->when($e->exists, fn ($q) => $q->whereKeyNot($e->id))->exists()) {
            throw new DomainException('That sign-in already belongs to another employee.');
        }
        $fields = ['name', 'gender', 'date_of_birth', 'phone', 'email', 'address', 'ghana_card_no', 'ssnit_no', 'department_id', 'branch_id', 'job_title', 'employment_type',
            'hire_date', 'contract_end', 'pay_method', 'bank_name', 'bank_account_no', 'momo_number', 'basic_salary_minor', 'transport_allowance_minor', 'meal_allowance_minor',
            'overtime_rate_bp', 'overtime_junior', 'tier3_bp', 'welfare_minor', 'ssnit_exempt', 'notes', 'user_id', 'takes_appointments', 'commission_bp'];

        return $this->recorder->transaction(function () use ($actor, $e, $data, $fields) {
            $values = [];
            foreach ($fields as $f) {
                if (array_key_exists($f, $data)) {
                    $values[$f] = is_string($data[$f]) ? (trim($data[$f]) === '' ? null : trim($data[$f])) : $data[$f];
                }
            }
            $e->forceFill($values + ['tenant_id' => $actor->tenantId]);
            if (! $e->exists) {
                $e->number = $this->numbers->next($actor->tenantId, 'employee');
                $e->status = 'active';
            }

            return $this->recorder->save($actor, $e, $e->exists ? 'employee.updated' : 'employee.hired', branchId: $e->branch_id);
        });
    }

    public function saveDepartment(Actor $actor, Department $d, string $code, string $name): Department
    {
        $this->require($actor, 'hr.manage');
        $code = strtoupper(trim($code));
        if ($code === '' || trim($name) === '') {
            throw new DomainException('Enter a code and a name.');
        }
        if (Department::where('code', $code)->when($d->exists, fn ($q) => $q->whereKeyNot($d->id))->exists()) {
            throw new DomainException('That code is already used.');
        }
        $d->forceFill(['tenant_id' => $actor->tenantId, 'code' => $code, 'name' => mb_substr(trim($name), 0, 255)]);

        return $this->recorder->save($actor, $d, $d->exists ? 'department.updated' : 'department.created');
    }

    public function terminate(Actor $actor, Employee $e, string $date, string $reason): Employee
    {
        $this->require($actor, 'hr.manage');
        if (trim($reason) === '') {
            throw new DomainException('Give the reason (resigned, dismissed, contract ended…).');
        }
        $e->forceFill(['status' => 'terminated', 'termination_date' => Carbon::parse($date)->toDateString(), 'termination_reason' => mb_substr(trim($reason), 0, 255)]);

        return $this->recorder->save($actor, $e, 'employee.terminated', $reason, $e->branch_id);
    }

    /** Working days (Mon–Fri) between two dates, inclusive. */
    public static function workingDays(string $from, string $to): int
    {
        $n = 0;
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay()) {
            $n += $d->isWeekday() ? 1 : 0;
        }

        return $n;
    }

    /** Days left of a leave type this calendar year (null = no fixed allowance). */
    public static function leaveBalance(Employee $e, LeaveType $type, int $year): ?int
    {
        if ($type->days_per_year === null) {
            return null;
        }
        $taken = (int) LeaveRequest::where('employee_id', $e->id)->where('leave_type_id', $type->id)->whereIn('status', ['requested', 'approved'])
            ->whereYear('starts_on', $year)->sum('days');

        return $type->days_per_year - $taken;
    }

    public function requestLeave(Actor $actor, Employee $e, LeaveType $type, string $from, string $to, ?string $reason = null, ?string $documentPath = null): LeaveRequest
    {
        $own = $e->user_id !== null && $e->user_id === $actor->id;
        // Your own leave: holding the permission anywhere is enough (cashiers are scoped to a till); for anyone else, HR.
        if (! $actor->user || ! (($own && $this->authz->allowsAnywhere($actor->user, 'leave.request')) || $this->authz->allows($actor->user, 'hr.manage'))) {
            throw new AccessDenied;
        }
        $start = Carbon::parse($from);
        $end = Carbon::parse($to);
        if ($end->lt($start) || ! $type->is_active) {
            throw new DomainException('The leave must end on or after the day it starts.');
        }
        $days = self::workingDays($start->toDateString(), $end->toDateString());
        if ($days === 0) {
            throw new DomainException('Those dates are all weekend days.');
        }
        if ($type->needs_document && ! $documentPath) {
            throw new DomainException("{$type->name} needs a supporting document (e.g. a doctor's note).");
        }
        $left = self::leaveBalance($e, $type, $start->year);
        if ($left !== null && $days > $left) {
            throw new DomainException("Only {$left} day(s) of {$type->name} left this year.");
        }
        if (LeaveRequest::where('employee_id', $e->id)->whereIn('status', ['requested', 'approved'])->where('starts_on', '<=', $end)->where('ends_on', '>=', $start)->exists()) {
            throw new DomainException('These dates overlap leave already requested.');
        }

        return $this->recorder->transaction(function () use ($actor, $e, $type, $start, $end, $days, $reason, $documentPath) {
            $r = (new LeaveRequest)->forceFill(['tenant_id' => $e->tenant_id, 'employee_id' => $e->id, 'leave_type_id' => $type->id, 'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(), 'days' => $days, 'reason' => $reason ? mb_substr($reason, 0, 255) : null, 'document_path' => $documentPath,
                'status' => 'requested', 'requested_by_name' => $actor->name]);

            return $this->recorder->save($actor, $r, 'leave.requested', branchId: $e->branch_id);
        });
    }

    public function decideLeave(Actor $actor, LeaveRequest $r, bool $approve, ?string $note = null): LeaveRequest
    {
        $e = Employee::findOrFail($r->employee_id);
        if (! $actor->user || ! $this->authz->allows($actor->user, 'leave.approve', Branch::findOrFail($e->branch_id))) {
            throw new AccessDenied;
        }
        if ($e->user_id === $actor->id) {
            throw new DomainException('Someone else must decide on your own leave.', 'own_document');
        }
        if (! $approve && trim((string) $note) === '') {
            throw new DomainException('Give a reason for refusing.');
        }

        return $this->recorder->transaction(function () use ($actor, $r, $approve, $note, $e) {
            $r = LeaveRequest::lockForUpdate()->findOrFail($r->id);
            if ($r->status !== 'requested') {
                throw new DomainException('This request is already decided.');
            }
            $r->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by_name' => $actor->name, 'decided_by' => $actor->id, 'decided_at' => now(),
                'decision_note' => $note ? mb_substr($note, 0, 255) : null]);

            return $this->recorder->save($actor, $r, $approve ? 'leave.approved' : 'leave.rejected', $note, $e->branch_id);
        });
    }

    public function cancelLeave(Actor $actor, LeaveRequest $r): LeaveRequest
    {
        $e = Employee::findOrFail($r->employee_id);
        $own = $e->user_id === $actor->id;
        if (! $actor->user || ! ($own || $this->authz->allows($actor->user, 'leave.approve', Branch::findOrFail($e->branch_id)))) {
            throw new AccessDenied;
        }
        if (! in_array($r->status, ['requested', 'approved'], true) || $r->starts_on->isPast() && ! $r->starts_on->isToday()) {
            throw new DomainException('Only leave that has not started can be cancelled.');
        }
        $r->status = 'cancelled';

        return $this->recorder->save($actor, $r, 'leave.cancelled', branchId: $e->branch_id);
    }

    /** Pay out a salary advance or staff loan; repaid by the monthly deduction from the first month given. */
    public function issueLoan(Actor $actor, Employee $e, string $kind, int $principal, int $monthly, string $firstMonth, Account $paidFrom, string $issuedOn, ?string $note = null): EmployeeLoan
    {
        $this->require($actor, 'loans.manage');
        if (! array_key_exists($kind, EmployeeLoan::KINDS) || $principal <= 0 || $monthly <= 0 || $monthly > $principal) {
            throw new DomainException('Enter the amount and a monthly repayment no larger than it.');
        }
        if (! BankingService::isMoneyAccount($paidFrom)) {
            throw new DomainException("{$paidFrom->label()} is not a cash, bank or mobile money account.");
        }
        if ($e->status !== 'active') {
            throw new DomainException('Only current employees can get an advance or loan.');
        }

        return $this->recorder->transaction(function () use ($actor, $e, $kind, $principal, $monthly, $firstMonth, $paidFrom, $issuedOn, $note) {
            $loan = (new EmployeeLoan)->forceFill(['tenant_id' => $e->tenant_id, 'number' => $this->numbers->next($e->tenant_id, 'employee_loan'), 'employee_id' => $e->id,
                'kind' => $kind, 'principal_minor' => $principal, 'monthly_minor' => $monthly, 'first_month' => Carbon::parse($firstMonth)->startOfMonth()->toDateString(),
                'paid_from_account_id' => $paidFrom->id, 'issued_on' => Carbon::parse($issuedOn)->toDateString(), 'note' => $note ? mb_substr($note, 0, 255) : null,
                'issued_by_name' => $actor->name]);

            return $this->recorder->save($actor, $loan, 'employee_loan.issued', branchId: $e->branch_id);
        });
    }

    /** The accountant confirms the statutory rules (PAYE bands, SSNIT rates) are current. */
    public function verifyProfile(Actor $actor, PayrollProfile $p, array $rules): PayrollProfile
    {
        $this->require($actor, 'payroll.approve');
        foreach ($rules['paye_bands'] ?? [] as $i => [$width, $bp]) {
            if (($width !== null && (int) $width <= 0) || (int) $bp < 0 || (int) $bp > 10000 || ($width === null && $i !== count($rules['paye_bands']) - 1)) {
                throw new DomainException('Each PAYE band needs a width above zero and a rate from 0 to 100 %; only the last band is open-ended.');
            }
        }
        $p->forceFill(['rules' => $rules, 'verified_by_name' => $actor->name, 'verified_at' => now()]);

        return $this->recorder->save($actor, $p, 'payroll_profile.verified');
    }

    private function require(Actor $actor, string $permission): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission)) {
            throw new AccessDenied;
        }
    }
}
