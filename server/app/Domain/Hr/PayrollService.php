<?php

namespace App\Domain\Hr;

use App\Domain\Banking\BankingService;
use App\Domain\Services\CommissionService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Account;
use App\Models\Employee;
use App\Models\EmployeeLoan;
use App\Models\LeaveRequest;
use App\Models\PayrollProfile;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Tenant;
use App\Security\Authorizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Monthly payroll (owner decision 2026-10-14: monthly; paid by bank, MoMo or cash). A draft is prepared from the
 * employee records, the month's attendance (overtime), approved unpaid leave and outstanding loans, and can be
 * recalculated until someone other than the preparer approves it. Approval posts it to the ledger (wages, SSNIT,
 * PAYE, Tier 2/3, welfare, loan repayments, salaries payable); paying it clears salaries payable from the bank and
 * the cash box.
 */
final class PayrollService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly DocumentNumbers $numbers) {}

    public function prepare(Actor $actor, int $year, int $month, ?string $payDate = null): PayrollRun
    {
        $this->require($actor, 'payroll.prepare');
        $start = Carbon::create($year, $month, 1);
        $end = $start->copy()->endOfMonth();
        $profile = PayrollProfile::where('effective_from', '<=', $end)->orderByDesc('effective_from')->first()
            ?? throw new DomainException('There is no payroll profile (PAYE and SSNIT rules) for this month.');

        return $this->recorder->transaction(function () use ($actor, $year, $month, $start, $end, $profile, $payDate) {
            $run = PayrollRun::where('year', $year)->where('month', $month)->lockForUpdate()->first();
            if ($run && $run->status !== 'draft') {
                throw new DomainException("The payroll for {$start->format('F Y')} is already {$run->status}.");
            }
            $run ??= (new PayrollRun)->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'payroll_run'), 'year' => $year,
                'month' => $month, 'status' => 'draft']);
            $run->forceFill(['period_start' => $start->toDateString(), 'period_end' => $end->toDateString(),
                'pay_date' => Carbon::parse($payDate ?? $end)->toDateString(), 'payroll_profile_id' => $profile->id, 'prepared_by_name' => $actor->name, 'prepared_by' => $actor->id]);
            $run->save();
            Payslip::where('payroll_run_id', $run->id)->delete(); // a draft is recalculated from scratch

            $tz = Tenant::find($actor->tenantId)->timezone ?: 'UTC';
            $working = HrService::workingDays($start->toDateString(), $end->toDateString());
            $employees = Employee::where('hire_date', '<=', $end)->where(fn ($q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $start))
                ->where(fn ($q) => $q->where('basic_salary_minor', '>', 0) // and commission-only staff who earned commission this month
                    ->orWhereExists(fn ($c) => $c->from('commissions')->whereColumn('commissions.employee_id', 'employees.id')
                        ->whereBetween('commissions.occurred_at', [$start->copy()->subDay(), $end->copy()->addDays(2)])))
                ->with('department')->orderBy('number')->get();
            foreach ($employees as $e) {
                // Days outside employment this month, and approved unpaid leave, are not paid.
                $outside = $e->hire_date->gt($start) ? HrService::workingDays($start->toDateString(), $e->hire_date->copy()->subDay()->toDateString()) : 0;
                $outside += $e->termination_date && $e->termination_date->lt($end) ? HrService::workingDays($e->termination_date->copy()->addDay()->toDateString(), $end->toDateString()) : 0;
                $unpaid = $outside + $this->unpaidLeaveDays($e, $start, $end);
                $sheet = $e->user_id ? AttendanceService::timesheet($e->user_id, $start->toDateString(), $end->toDateString(), (int) ($profile->rules['standard_day_hours'] ?? 8), $tz)
                    : ['overtime_centi' => 0, 'days' => []];
                $loans = EmployeeLoan::where('employee_id', $e->id)->where('first_month', '<=', $start)->orderBy('issued_on')->get()
                    ->map(fn ($l) => ['id' => $l->id, 'monthly_minor' => $l->monthly_minor, 'balance_minor' => $l->principal_minor - $l->repaidMinor()])
                    ->filter(fn ($l) => $l['balance_minor'] > 0)->values()->all();
                $c = PayrollCalculator::compute($e->only(['basic_salary_minor', 'transport_allowance_minor', 'meal_allowance_minor', 'overtime_rate_bp', 'overtime_junior',
                    'tier3_bp', 'welfare_minor', 'ssnit_exempt']), $profile->rules, ['working_days' => $working, 'unpaid_days' => $unpaid,
                        'overtime_centi_hours' => $sheet['overtime_centi'], 'loans' => $loans, 'tips_minor' => $this->tips($e->user_id, $start, $end, $tz),
                        'commission_minor' => CommissionService::total($e->id, $start, $end, $tz)]);
                Payslip::create(['id' => (string) Str::uuid7(), 'tenant_id' => $e->tenant_id, 'payroll_run_id' => $run->id, 'employee_id' => $e->id, 'branch_id' => $e->branch_id,
                    'employee' => ['name' => $e->name, 'number' => $e->number, 'job_title' => $e->job_title, 'department' => $e->department?->name,
                        'ssnit_no' => $e->ssnit_no, 'ghana_card_no' => $e->ghana_card_no, 'pay_method' => $e->pay_method, 'bank_name' => $e->bank_name,
                        'bank_account_no' => $e->bank_account_no, 'momo_number' => $e->momo_number],
                    'days_worked' => count($sheet['days'])] + collect($c)->except(['overtime_hours'])->all() + ['overtime_hours' => $c['overtime_hours']]);
            }
            $run->totals = $this->totals($run);

            return $this->recorder->save($actor, $run, 'payroll.prepared');
        });
    }

    /** Tips the person received on till sales this month (owed to them since; paid out with this payroll). */
    private function tips(?string $userId, Carbon $start, Carbon $end, string $tz): int
    {
        if (! $userId) {
            return 0;
        }

        return (int) DB::table('sales')->where('tip_user_id', $userId)->where('status', 'posted')
            ->where('occurred_at', '>=', $start->copy()->shiftTimezone($tz)->startOfDay()->utc())
            ->where('occurred_at', '<', $end->copy()->shiftTimezone($tz)->addDay()->startOfDay()->utc())->sum('tip_minor');
    }

    public function approve(Actor $actor, PayrollRun $run): PayrollRun
    {
        $this->require($actor, 'payroll.approve');
        if ($run->prepared_by === $actor->id) {
            throw new DomainException('Someone other than the person who prepared it must approve the payroll.', 'own_document');
        }

        return $this->recorder->transaction(function () use ($actor, $run) {
            $run = PayrollRun::lockForUpdate()->findOrFail($run->id);
            if ($run->status !== 'draft') {
                throw new DomainException('Only a draft payroll can be approved.');
            }
            if ($run->payslips()->count() === 0) {
                throw new DomainException('This payroll has no payslips.');
            }
            foreach ($run->payslips as $p) {
                foreach ($p->loan_deductions as $d) {
                    DB::table('loan_repayments')->insert(['id' => (string) Str::uuid7(), 'tenant_id' => $run->tenant_id, 'employee_loan_id' => $d['loan_id'],
                        'payslip_id' => $p->id, 'amount_minor' => $d['amount_minor'], 'created_at' => now()]);
                }
            }
            $run->forceFill(['status' => 'approved', 'approved_by_name' => $actor->name, 'approved_at' => now()]);

            return $this->recorder->save($actor, $run, 'payroll.approved');
        });
    }

    /** Net pay handed over: bank and MoMo staff from the bank account, cash staff from the cash account. */
    public function pay(Actor $actor, PayrollRun $run, ?Account $bank, ?Account $cash, string $paidOn): PayrollRun
    {
        $this->require($actor, 'payroll.pay');
        $byCash = (int) $run->payslips()->get()->filter(fn ($p) => ($p->employee['pay_method'] ?? '') === 'cash')->sum('net_minor');
        $byBank = (int) $run->payslips()->sum('net_minor') - $byCash;
        foreach ([[$bank, $byBank, 'bank or MoMo'], [$cash, $byCash, 'cash']] as [$acc, $amount, $label]) {
            if ($amount > 0 && (! $acc || ! BankingService::isMoneyAccount($acc))) {
                throw new DomainException("Choose the account the {$label} pay comes from.");
            }
        }

        return $this->recorder->transaction(function () use ($actor, $run, $bank, $cash, $paidOn, $byBank, $byCash) {
            $run = PayrollRun::lockForUpdate()->findOrFail($run->id);
            if ($run->status !== 'approved') {
                throw new DomainException('Approve the payroll before paying it.');
            }
            $run->forceFill(['status' => 'paid', 'bank_account_id' => $byBank > 0 ? $bank->id : null, 'cash_account_id' => $byCash > 0 ? $cash->id : null,
                'paid_on' => Carbon::parse($paidOn)->toDateString(), 'paid_by_name' => $actor->name]);

            return $this->recorder->save($actor, $run, 'payroll.paid');
        });
    }

    private function unpaidLeaveDays(Employee $e, Carbon $start, Carbon $end): int
    {
        $days = 0;
        foreach (LeaveRequest::with('leaveType')->where('employee_id', $e->id)->where('status', 'approved')->where('starts_on', '<=', $end)->where('ends_on', '>=', $start)->get() as $l) {
            if (! $l->leaveType->paid) {
                $days += HrService::workingDays(($l->starts_on->gt($start) ? $l->starts_on : $start)->toDateString(), ($l->ends_on->lt($end) ? $l->ends_on : $end)->toDateString());
            }
        }

        return $days;
    }

    private function totals(PayrollRun $run): array
    {
        $cols = ['gross_minor', 'paye_minor', 'ssnit_employee_minor', 'ssnit_employer_minor', 'tier1_minor', 'tier2_minor', 'tier3_minor', 'welfare_minor', 'loans_minor', 'net_minor'];
        $sum = DB::table('payslips')->where('payroll_run_id', $run->id)->selectRaw('COUNT(*) AS n, '.implode(', ', array_map(fn ($c) => "COALESCE(SUM({$c}), 0) AS {$c}", $cols)))->first();

        return array_map('intval', (array) $sum);
    }

    private function require(Actor $actor, string $permission): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission)) {
            throw new AccessDenied;
        }
    }
}
