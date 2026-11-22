<?php

namespace App\Domain\Office;

use App\Domain\Hr\AttendanceService;
use App\Domain\Hr\HrService;
use App\Filament\Pages\MyHr;
use App\Filament\Pages\Timesheets;
use App\Filament\Resources\EmployeeLoans\EmployeeLoanResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LeaveRequests\LeaveRequestResource;
use App\Filament\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Support\Erp;
use App\Filament\Support\FinanceUi;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\PayrollProfile;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Models\User;
use App\Support\IndustryContext;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * HR & payroll in the new back office (part 21 of the rewrite), as the current pages: Employees, Timesheets, Leave,
 * Payroll, Loans & advances and My leave & payslips. Same queries (the resources' and pages') and rules: HrService,
 * PayrollService and AttendanceService (HrController). Pay and bank details only reach people who manage HR.
 */
final class HrScreen
{
    public const PER_PAGE = [10, 25, 50, 100];

    public function __construct(private readonly User $user) {}

    private static function choices(array $map): array
    {
        return collect($map)->map(fn ($l, $k) => ['id' => (string) $k, 'label' => (string) $l])->values()->all();
    }

    private static function page(array $p): array
    {
        return [in_array((int) ($p['per'] ?? 0), self::PER_PAGE, true) ? (int) $p['per'] : 25, max(1, (int) ($p['page'] ?? 1))];
    }

    private function currency(): string
    {
        return $this->user->tenant?->currency_code ?? 'GHS';
    }

    private function m(): array
    {
        $c = $this->currency();

        return ['currency' => $c, 'exponent' => Money::exponent($c)];
    }

    /** Minor units as the plain amount a form field takes (1234.50), empty for nothing. */
    private function amount(?int $minor): string
    {
        $e = Money::exponent($this->currency());

        return $minor ? number_format($minor / (10 ** $e), $e, '.', '') : '';
    }

    private function tz(): string
    {
        return Tenant::find($this->user->tenant_id)?->timezone ?: 'UTC';
    }

    // -------------------------------------------------------------------------------------------- Employees

    public static function canEmployees(): bool
    {
        return EmployeeResource::canViewAny();
    }

    public function employeesQuery(): Builder
    {
        return EmployeeResource::getEloquentQuery();
    }

    /** @param  array<string, mixed>  $p  q (number, name, phone), status (active / terminated / all), branch, show, page, per */
    public function employees(array $p): array
    {
        $q = $this->employeesQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('employees.number', 'ilike', "%{$s}%")->orWhere('employees.name', 'ilike', "%{$s}%")->orWhere('employees.phone', 'ilike', "%{$s}%"));
        }
        $status = (string) ($p['status'] ?? 'active');
        if (in_array($status, ['active', 'terminated'], true)) {
            $q->where('employees.status', $status);
        }
        if (array_key_exists((string) ($p['branch'] ?? ''), FinanceUi::branches())) {
            $q->where('employees.branch_id', $p['branch']);
        }
        match ($p['show'] ?? '') {
            'archived' => $q->whereNotNull('employees.archived_at'),
            'both' => null,
            default => $q->whereNull('employees.archived_at'),
        };
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $manage = Erp::canAnywhere('hr.manage');
        $archive = new CompanyScreen($this->user);

        return ['total' => $total, 'page' => $page, 'per' => $per, 'status' => $status, 'branches' => self::choices(FinanceUi::branches()),
            'can_create' => EmployeeResource::canCreate(), 'can_manage' => $manage,
            'rows' => $q->orderBy('employees.number')->forPage($page, $per)->get()->map(fn (Employee $e) => ['id' => $e->id, 'number' => $e->number, 'name' => $e->name,
                'job_title' => $e->job_title, 'branch' => $e->branch?->code, 'department' => $e->department?->name, 'type' => Employee::TYPES[$e->employment_type] ?? $e->employment_type,
                'hire_date' => $e->hire_date?->toDateString(), 'status' => $e->status] + $archive->archiveOf($e))->all()];
    }

    /** The employee form (new or existing), with its choices: only people who manage HR open it. */
    public function employeeForm(?Employee $e): array
    {
        $users = IndustryContext::userScope(User::where('is_active', true))->orderBy('name')->get()->mapWithKeys(fn ($u) => [$u->id => "{$u->name} ({$u->username})"])->all();
        $v = $e ? $e->only(['name', 'gender', 'phone', 'email', 'address', 'ghana_card_no', 'ssnit_no', 'department_id', 'branch_id', 'job_title', 'employment_type',
            'pay_method', 'bank_name', 'bank_account_no', 'momo_number', 'notes', 'user_id']) : [];
        $v = array_map(fn ($x) => (string) ($x ?? ''), $v) + ['name' => '', 'gender' => '', 'phone' => '', 'email' => '', 'address' => '', 'ghana_card_no' => '', 'ssnit_no' => '',
            'department_id' => '', 'branch_id' => (string) FinanceUi::hq(), 'job_title' => '', 'employment_type' => 'permanent', 'pay_method' => 'bank', 'bank_name' => '',
            'bank_account_no' => '', 'momo_number' => '', 'notes' => '', 'user_id' => ''];

        return $this->m() + ['id' => $e?->id, 'number' => $e?->number, 'status' => $e?->status ?? 'active', 'can_terminate' => $e && $e->status === 'active' && Erp::canAnywhere('hr.manage'),
            'values' => $v + ['date_of_birth' => (string) $e?->date_of_birth?->toDateString(), 'hire_date' => $e?->hire_date?->toDateString() ?? now()->toDateString(),
                'contract_end' => (string) $e?->contract_end?->toDateString(), 'basic_salary' => $this->amount($e?->basic_salary_minor),
                'transport_allowance' => $this->amount($e?->transport_allowance_minor), 'meal_allowance' => $this->amount($e?->meal_allowance_minor),
                'welfare' => $this->amount($e?->welfare_minor), 'overtime_rate' => $e ? (string) ($e->overtime_rate_bp / 10000) : '1.5',
                'tier3_pct' => $e ? (string) ($e->tier3_bp / 100) : '0', 'commission_pct' => $e ? (string) ($e->commission_bp / 100) : '0',
                'overtime_junior' => (bool) $e?->overtime_junior, 'ssnit_exempt' => (bool) $e?->ssnit_exempt, 'takes_appointments' => (bool) $e?->takes_appointments],
            'genders' => self::choices(['female' => 'Female', 'male' => 'Male']), 'users' => self::choices($users), 'branches' => self::choices(FinanceUi::branches()),
            'departments' => self::choices(Department::where(fn ($q) => $q->whereNull('archived_at')->orWhere('id', $e?->department_id))->orderBy('name')->pluck('name', 'id')->all()),
            'types' => self::choices(Employee::TYPES), 'pay_methods' => self::choices(Employee::PAY_METHODS)]
            + ($e ? (new CompanyScreen($this->user))->archiveOf($e) : ['archived' => false, 'can_archive' => false, 'archive_blockers' => [], 'can_restore' => false]);
    }

    // -------------------------------------------------------------------------------------------- Timesheets

    public static function canTimesheets(): bool
    {
        return Timesheets::canAccess();
    }

    /** @param  array<string, mixed>  $p  branch, month (YYYY-MM), person (a user) */
    public function timesheets(array $p): array
    {
        $ids = Erp::branchIds('attendance.manage');
        $branches = Branch::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('code')->get()->mapWithKeys(fn ($b) => [$b->id => "{$b->code} · {$b->name}"])->all();
        $branch = array_key_exists((string) ($p['branch'] ?? ''), $branches) ? (string) $p['branch'] : (string) ($ids === null ? FinanceUi::hq() : array_key_first($branches));
        $month = preg_match('/^\d{4}-\d{2}$/', (string) ($p['month'] ?? '')) ? (string) $p['month'] : now()->format('Y-m');
        $start = Carbon::parse($month.'-01');
        [$from, $to] = [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
        $tz = $this->tz();
        $hours = (int) (PayrollProfile::orderByDesc('effective_from')->first()?->rules['standard_day_hours'] ?? 8);
        $rows = [];
        $people = Employee::where('branch_id', $branch)->whereNotNull('user_id')->where('status', 'active')->orderBy('name')->get();
        foreach ($people as $e) {
            $s = AttendanceService::timesheet($e->user_id, $from, $to, $hours, $tz);
            $rows[] = ['id' => $e->user_id, 'name' => $e->name, 'days' => count($s['days']), 'hours' => number_format($s['total_centi'] / 100, 2),
                'overtime' => number_format($s['overtime_centi'] / 100, 2), 'open' => array_values($s['open'])];
        }
        $person = $people->firstWhere('user_id', (string) ($p['person'] ?? ''));
        $events = [];
        if ($person) {
            $list = DB::table('attendance_events')->where('user_id', $person->user_id)
                ->whereBetween('occurred_at', [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()])->orderBy('occurred_at')->get();
            $voided = $list->whereNotNull('voids_id')->pluck('voids_id')->flip();
            $events = $list->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind, 'at' => Carbon::parse($r->occurred_at, 'UTC')->setTimezone($tz)->format('D j M H:i'),
                'source' => $r->source, 'reason' => $r->reason, 'by' => $r->recorded_by_name, 'voided' => isset($voided[$r->id]), 'is_void' => (bool) $r->voids_id])->values()->all();
        }

        return ['branch' => $branch, 'month' => $month, 'branches' => self::choices($branches), 'rows' => $rows,
            'person' => $person ? ['id' => $person->user_id, 'name' => $person->name] : null, 'events' => $events];
    }

    // -------------------------------------------------------------------------------------------- Leave

    public static function canLeave(): bool
    {
        return LeaveRequestResource::canViewAny();
    }

    public function leaveQuery(): Builder
    {
        return LeaveRequestResource::getEloquentQuery();
    }

    public const LEAVE_TONE = ['approved' => 'success', 'rejected' => 'subtle', 'cancelled' => 'subtle', 'requested' => 'warning'];

    /** @param  array<string, mixed>  $p  q (employee), status, page, per */
    public function leave(array $p): array
    {
        $q = $this->leaveQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->whereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$s}%"));
        }
        if (array_key_exists($p['status'] ?? '', LeaveRequest::STATUSES)) {
            $q->where('leave_requests.status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $waiting = (clone $q)->reorder()->where('leave_requests.status', 'requested')->count();

        return ['total' => $total, 'waiting' => $waiting, 'page' => $page, 'per' => $per, 'statuses' => self::choices(LeaveRequest::STATUSES),
            'can_record' => Erp::canAnywhere('hr.manage'),
            'rows' => $q->orderByDesc('leave_requests.starts_on')->forPage($page, $per)->get()->map(fn (LeaveRequest $r) => $this->leaveRow($r))->all()];
    }

    private function leaveRow(LeaveRequest $r): array
    {
        $mine = $r->employee?->user_id === $this->user->id;

        return ['id' => $r->id, 'employee' => $r->employee?->name, 'type' => $r->leaveType?->name, 'starts_on' => $r->starts_on?->toDateString(), 'ends_on' => $r->ends_on?->toDateString(),
            'days' => (int) $r->days, 'reason' => $r->reason, 'note' => $r->decision_note, 'status' => $r->status, 'status_label' => LeaveRequest::STATUSES[$r->status] ?? $r->status,
            'tone' => self::LEAVE_TONE[$r->status] ?? 'informative', 'document' => (bool) $r->document_path,
            'can_decide' => $r->status === 'requested' && ! $mine, 'can_cancel' => in_array($r->status, ['requested', 'approved'], true) && ! $r->starts_on->isPast()];
    }

    /** What Request leave and Record leave for someone offer. */
    public function leaveForm(bool $chooseEmployee): array
    {
        return ['types' => self::choices(LeaveType::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
            'employees' => $chooseEmployee ? self::choices(Employee::where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()) : []];
    }

    // -------------------------------------------------------------------------------------------- Payroll

    public static function canPayroll(): bool
    {
        return PayrollRunResource::canViewAny();
    }

    public const RUN_TONE = ['paid' => 'success', 'approved' => 'informative', 'draft' => 'warning'];

    public function runs(array $p): array
    {
        $q = PayrollRun::query();
        if (array_key_exists($p['status'] ?? '', PayrollRun::STATUSES)) {
            $q->where('status', $p['status']);
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $t = fn (PayrollRun $r, string $k) => (int) ($r->totals[$k] ?? 0);

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'statuses' => self::choices(PayrollRun::STATUSES),
            'can_prepare' => Erp::canAnywhere('payroll.prepare'), 'months' => self::choices(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => date('F', mktime(0, 0, 0, $m, 1))])->all()),
            'now' => ['month' => (string) now()->month, 'year' => (string) now()->year, 'pay_date' => now()->endOfMonth()->toDateString()],
            'rows' => $q->orderByDesc('period_start')->forPage($page, $per)->get()->map(fn (PayrollRun $r) => ['id' => $r->id, 'number' => $r->number,
                'month' => $r->period_start?->format('F Y'), 'people' => (int) ($r->totals['n'] ?? 0), 'gross' => $t($r, 'gross_minor'), 'paye' => $t($r, 'paye_minor'),
                'net' => $t($r, 'net_minor'), 'status' => $r->status, 'status_label' => PayrollRun::STATUSES[$r->status] ?? $r->status, 'tone' => self::RUN_TONE[$r->status] ?? 'informative'])->all()];
    }

    public function run(PayrollRun $r): array
    {
        $r->loadMissing('payslips', 'profile');
        $t = fn (string $k) => (int) ($r->totals[$k] ?? 0);
        $pay = $r->status === 'approved' && Erp::canAnywhere('payroll.pay');

        return $this->m() + ['id' => $r->id, 'number' => $r->number, 'month' => $r->period_start?->format('F Y'), 'status' => $r->status,
            'status_label' => PayrollRun::STATUSES[$r->status] ?? $r->status, 'tone' => self::RUN_TONE[$r->status] ?? 'informative', 'pay_date' => $r->pay_date?->toDateString(),
            'gross' => $t('gross_minor'), 'paye' => $t('paye_minor'), 'ssnit' => $t('ssnit_employee_minor') + $t('ssnit_employer_minor'), 'net' => $t('net_minor'),
            'prepared_by' => $r->prepared_by_name, 'approved_by' => $r->approved_by_name, 'paid' => $r->paid_by_name ? "{$r->paid_by_name}, ".$r->paid_on?->format('j M Y') : null,
            'profile' => $r->profile?->name, 'checked' => $r->profile?->verified_at ? "Checked by {$r->profile->verified_by_name}" : null,
            'print' => ['payslips' => "/print/payroll/{$r->id}/payslips", 'sheet' => "/print/payroll/{$r->id}/pay-sheet", 'list' => "/print/payroll/{$r->id}/pay-list.csv"],
            'payslips' => $r->payslips->sortBy(fn ($p) => $p->employee['name'] ?? '')->values()->map(fn (Payslip $p) => ['id' => $p->id, 'employee' => $p->employee['name'] ?? '—',
                'gross' => (int) $p->gross_minor, 'ssnit' => (int) $p->ssnit_employee_minor, 'paye' => (int) $p->paye_minor, 'net' => (int) $p->net_minor,
                'paid_by' => Employee::PAY_METHODS[$p->employee['pay_method'] ?? ''] ?? (string) ($p->employee['pay_method'] ?? ''), 'open' => "/print/payslip/{$p->id}"])->all(),
            'actions' => ['recalculate' => $r->status === 'draft' && Erp::canAnywhere('payroll.prepare'),
                'approve' => $r->status === 'draft' && Erp::canAnywhere('payroll.approve') && $r->prepared_by !== $this->user->id,
                'pay' => $pay ? ['accounts' => self::choices(FinanceUi::moneyAccounts()), 'bank' => (string) Account::where('system_key', 'bank')->value('id'),
                    'cash' => (string) Account::where('system_key', 'cash_office')->value('id'), 'today' => now()->toDateString()] : null]];
    }

    // -------------------------------------------------------------------------------------------- Loans & advances

    public static function canLoans(): bool
    {
        return EmployeeLoanResource::canViewAny();
    }

    public function loans(array $p): array
    {
        $q = EmployeeLoanResource::getEloquentQuery();
        if (($s = trim((string) ($p['q'] ?? ''))) !== '') {
            $q->where(fn ($w) => $w->where('employee_loans.number', 'ilike', "%{$s}%")->orWhereHas('employee', fn ($e) => $e->where('name', 'ilike', "%{$s}%")));
        }
        [$per, $page] = self::page($p);
        $total = (clone $q)->count();
        $issue = Erp::canAnywhere('loans.manage');

        return $this->m() + ['total' => $total, 'page' => $page, 'per' => $per, 'can_issue' => $issue,
            'form' => $issue ? ['employees' => self::choices(Employee::where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()), 'kinds' => self::choices(EmployeeLoan::KINDS),
                'accounts' => self::choices(FinanceUi::moneyAccounts()), 'account' => (string) Account::where('system_key', 'cash_office')->value('id'),
                'first_month' => now()->startOfMonth()->toDateString(), 'today' => now()->toDateString()] : null,
            'rows' => $q->orderByDesc('employee_loans.issued_on')->forPage($page, $per)->get()->map(fn (EmployeeLoan $l) => ['id' => $l->id, 'number' => $l->number,
                'employee' => $l->employee?->name, 'kind' => EmployeeLoan::KINDS[$l->kind] ?? $l->kind, 'issued_on' => $l->issued_on?->toDateString(),
                'amount' => (int) $l->principal_minor, 'monthly' => (int) $l->monthly_minor, 'owed' => (int) $l->principal_minor - $l->repaidMinor()])->all()];
    }

    // -------------------------------------------------------------------------------------------- My leave & payslips

    public static function canMine(): bool
    {
        return MyHr::canAccess();
    }

    public function me(): Employee
    {
        return Employee::where('user_id', $this->user->id)->firstOrFail();
    }

    public function mine(): array
    {
        $e = $this->me();

        return $this->m() + ['name' => $e->name, 'types' => self::choices(LeaveType::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all()),
            'balances' => LeaveType::where('is_active', true)->orderBy('name')->get()->map(fn ($t) => ['name' => $t->name, 'left' => HrService::leaveBalance($e, $t, now()->year),
                'allowance' => $t->days_per_year])->all(),
            'requests' => LeaveRequest::with('leaveType', 'employee')->where('employee_id', $e->id)->orderByDesc('starts_on')->limit(20)->get()
                ->map(fn (LeaveRequest $r) => ['can_decide' => false] + $this->leaveRow($r))->all(),
            'payslips' => Payslip::with('run')->where('employee_id', $e->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))->orderByDesc('created_at')->limit(24)->get()
                ->map(fn (Payslip $p) => ['id' => $p->id, 'month' => $p->run?->period_start?->format('F Y'), 'net' => (int) $p->net_minor, 'open' => "/print/payslip/{$p->id}"])->all()];
    }
}
