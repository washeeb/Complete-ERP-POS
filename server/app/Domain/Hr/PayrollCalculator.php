<?php

namespace App\Domain\Hr;

use App\Support\Qty;

/**
 * One employee's month, in integer pesewas (no floats), on a statutory profile:
 *
 *   basic earned  = basic − unpaid leave (basic ÷ working days × unpaid days)
 *   overtime      = basic ÷ (working days × standard hours) × overtime hours × overtime rate
 *   gross         = basic earned + transport + meal + overtime
 *   SSNIT (5.5 %) on basic earned; Tier 3 relieved up to the profile's % of basic
 *   taxable       = gross − SSNIT − relieved Tier 3 (− overtime of qualifying junior staff, taxed separately at 5 % / 10 %)
 *   PAYE          = monthly bands on taxable
 *   net           = gross − SSNIT − PAYE − overtime tax − Tier 3 − welfare − loan repayments (never below zero)
 *   employer      = SSNIT 13 %; remitted as Tier 1 (13.5 %) and Tier 2 (5 %) of basic earned
 */
final class PayrollCalculator
{
    /**
     * @param  array{basic_salary_minor: int, transport_allowance_minor: int, meal_allowance_minor: int, overtime_rate_bp: int, overtime_junior: bool, tier3_bp: int, welfare_minor: int, ssnit_exempt: bool}  $e
     * @param  array{working_days: int, unpaid_days?: int, overtime_centi_hours?: int, tips_minor?: int, loans?: list<array{id: string, monthly_minor: int, balance_minor: int}>}  $month
     */
    public static function compute(array $e, array $rules, array $month): array
    {
        $basic = (int) $e['basic_salary_minor'];
        $working = max(1, (int) $month['working_days']);
        $unpaidDays = min($working, (int) ($month['unpaid_days'] ?? 0));
        $unpaid = Qty::divRound($basic * $unpaidDays, $working);
        $basicEarned = $basic - $unpaid;
        $hours = (int) ($rules['standard_day_hours'] ?? 8);
        $otCenti = max(0, (int) ($month['overtime_centi_hours'] ?? 0));
        $overtime = Qty::divRound($basic * $otCenti * (int) $e['overtime_rate_bp'], $working * $hours * 100 * 10000);
        $transport = (int) $e['transport_allowance_minor'];
        $meal = (int) $e['meal_allowance_minor'];
        $tips = max(0, (int) ($month['tips_minor'] ?? 0)); // Restaurant: tips received through the till, taxable income
        $gross = $basicEarned + $transport + $meal + $overtime + $tips;

        $s = $rules['ssnit'];
        $ssnitEmp = $e['ssnit_exempt'] ? 0 : self::pct($basicEarned, $s['employee_bp']);
        $ssnitEr = $e['ssnit_exempt'] ? 0 : self::pct($basicEarned, $s['employer_bp']);
        $tier1 = $e['ssnit_exempt'] ? 0 : self::pct($basicEarned, $s['tier1_bp']);
        $tier2 = $ssnitEmp + $ssnitEr - $tier1; // the rest of the 18.5 %, so the split always adds up
        $tier3 = self::pct($basicEarned, (int) $e['tier3_bp']);
        $tier3Relief = min($tier3, self::pct($basicEarned, (int) ($rules['tier3_relief_bp'] ?? 0)));

        $otTax = 0;
        $taxableOt = $overtime;
        if ($e['overtime_junior'] && $overtime > 0) {
            $j = $rules['overtime_junior'];
            $cap = self::pct($basic, $j['threshold_bp']);
            $otTax = self::pct(min($overtime, $cap), $j['low_bp']) + self::pct(max(0, $overtime - $cap), $j['high_bp']);
            $taxableOt = 0;
        }
        $allowances = ($rules['allowances_taxable'] ?? true) ? $transport + $meal : 0;
        $taxable = max(0, $basicEarned + $allowances + $taxableOt + $tips - $ssnitEmp - $tier3Relief);
        $paye = self::paye($taxable, $rules['paye_bands']);

        $welfare = (int) $e['welfare_minor'];
        $available = $gross - $ssnitEmp - $paye - $otTax - $tier3 - $welfare;
        $loanDeductions = [];
        foreach ($month['loans'] ?? [] as $l) {
            $take = max(0, min((int) $l['monthly_minor'], (int) $l['balance_minor'], $available));
            if ($take > 0) {
                $loanDeductions[] = ['loan_id' => $l['id'], 'amount_minor' => $take];
                $available -= $take;
            }
        }
        $loans = array_sum(array_column($loanDeductions, 'amount_minor'));
        $net = $gross - $ssnitEmp - $paye - $otTax - $tier3 - $welfare - $loans;

        $lines = array_values(array_filter([
            ['code' => 'BASIC', 'label' => 'Basic salary', 'kind' => 'earning', 'amount_minor' => $basic],
            ['code' => 'UNPAID', 'label' => "Unpaid leave ({$unpaidDays} day(s))", 'kind' => 'earning', 'amount_minor' => -$unpaid],
            ['code' => 'TRANSPORT', 'label' => 'Transport allowance', 'kind' => 'earning', 'amount_minor' => $transport],
            ['code' => 'MEAL', 'label' => 'Meal allowance', 'kind' => 'earning', 'amount_minor' => $meal],
            ['code' => 'OVERTIME', 'label' => 'Overtime ('.number_format($otCenti / 100, 2).' h)', 'kind' => 'earning', 'amount_minor' => $overtime],
            ['code' => 'TIPS', 'label' => 'Tips', 'kind' => 'earning', 'amount_minor' => $tips],
            ['code' => 'SSNIT', 'label' => 'SSNIT (employee 5.5 %)', 'kind' => 'deduction', 'amount_minor' => $ssnitEmp],
            ['code' => 'PAYE', 'label' => 'Income tax (PAYE)', 'kind' => 'deduction', 'amount_minor' => $paye],
            ['code' => 'OT_TAX', 'label' => 'Tax on overtime', 'kind' => 'deduction', 'amount_minor' => $otTax],
            ['code' => 'TIER3', 'label' => 'Provident fund (Tier 3)', 'kind' => 'deduction', 'amount_minor' => $tier3],
            ['code' => 'WELFARE', 'label' => 'Staff welfare', 'kind' => 'deduction', 'amount_minor' => $welfare],
            ['code' => 'LOANS', 'label' => 'Loan / advance repayment', 'kind' => 'deduction', 'amount_minor' => $loans],
            ['code' => 'SSNIT_ER', 'label' => 'SSNIT (employer 13 %)', 'kind' => 'employer', 'amount_minor' => $ssnitEr],
        ], fn ($l) => $l['amount_minor'] !== 0 || $l['code'] === 'BASIC'));

        return ['basic_minor' => $basicEarned, 'gross_minor' => $gross, 'taxable_minor' => $taxable, 'paye_minor' => $paye + $otTax,
            'ssnit_employee_minor' => $ssnitEmp, 'ssnit_employer_minor' => $ssnitEr, 'tier1_minor' => $tier1, 'tier2_minor' => $tier2, 'tier3_minor' => $tier3,
            'welfare_minor' => $welfare, 'loans_minor' => $loans, 'net_minor' => $net, 'tips_minor' => $tips, 'lines' => $lines, 'loan_deductions' => $loanDeductions,
            'unpaid_leave_days' => $unpaidDays, 'overtime_hours' => $otCenti / 100];
    }

    /** Progressive monthly PAYE: each band's width taxed at its rate, rounded per band. */
    public static function paye(int $taxable, array $bands): int
    {
        $tax = 0;
        $left = $taxable;
        foreach ($bands as [$width, $bp]) {
            if ($left <= 0) {
                break;
            }
            $slice = $width === null ? $left : min($left, (int) $width);
            $tax += self::pct($slice, (int) $bp);
            $left -= $slice;
        }

        return $tax;
    }

    private static function pct(int $amount, int $bp): int
    {
        return Qty::divRound($amount * $bp, 10000);
    }
}
