<?php

use App\Domain\Hr\HrBaseline;
use App\Domain\Hr\PayrollCalculator;

/** Worked by hand on the seeded Ghana 2024 profile (monthly bands), so the accountant can follow each figure. */
function emp(array $over = []): array
{
    return $over + ['basic_salary_minor' => 300000, 'transport_allowance_minor' => 0, 'meal_allowance_minor' => 0, 'overtime_rate_bp' => 15000,
        'overtime_junior' => false, 'tier3_bp' => 0, 'welfare_minor' => 0, 'ssnit_exempt' => false];
}

it('applies the monthly PAYE bands progressively', function () {
    $bands = HrBaseline::GHANA_2024['paye_bands'];
    expect(PayrollCalculator::paye(49000, $bands))->toBe(0)               // first GHS 490 free
        ->and(PayrollCalculator::paye(60000, $bands))->toBe(550)          // next 110 at 5 %
        ->and(PayrollCalculator::paye(500000, $bands))->toBe(84850)       // GHS 5,000 → GHS 848.50
        ->and(PayrollCalculator::paye(6000000, $bands))->toBe(1708284);   // GHS 60,000 reaches the 35 % band
});

it('works out a salaried month: allowances, SSNIT tiers, Tier 3 relief, welfare', function () {
    $r = PayrollCalculator::compute(emp(['transport_allowance_minor' => 30000, 'meal_allowance_minor' => 20000, 'tier3_bp' => 500, 'welfare_minor' => 2000]),
        HrBaseline::GHANA_2024, ['working_days' => 22]);
    // gross 3,500; SSNIT 5.5 % of 3,000 = 165; Tier 3 5 % = 150 (relieved); taxable 3,500 − 165 − 150 = 3,185 → PAYE 448.13
    expect($r)->toMatchArray(['gross_minor' => 350000, 'ssnit_employee_minor' => 16500, 'tier3_minor' => 15000, 'taxable_minor' => 318500, 'paye_minor' => 44813,
        'net_minor' => 350000 - 16500 - 44813 - 15000 - 2000, 'ssnit_employer_minor' => 39000, 'tier1_minor' => 40500, 'tier2_minor' => 15000]);
    expect($r['tier1_minor'] + $r['tier2_minor'])->toBe($r['ssnit_employee_minor'] + $r['ssnit_employer_minor']); // 18.5 % in full
});

it('docks unpaid days, taxes junior staff overtime separately and never takes loans below zero pay', function () {
    $unpaid = PayrollCalculator::compute(emp(), HrBaseline::GHANA_2024, ['working_days' => 22, 'unpaid_days' => 2]);
    expect($unpaid['basic_minor'])->toBe(300000 - 27273)->and($unpaid['unpaid_leave_days'])->toBe(2);

    $ot = PayrollCalculator::compute(emp(['basic_salary_minor' => 200000, 'overtime_junior' => true]), HrBaseline::GHANA_2024, ['working_days' => 22, 'overtime_centi_hours' => 1000]);
    // hourly 2,000 ÷ (22 × 8) = 11.36; 10 h × 1.5 = 170.45 overtime, taxed 5 % = 8.52 and kept out of PAYE income
    expect($ot['gross_minor'])->toBe(217045)->and($ot['taxable_minor'])->toBe(200000 - 11000)
        ->and($ot['paye_minor'])->toBe(PayrollCalculator::paye(189000, HrBaseline::GHANA_2024['paye_bands']) + 852);

    $loan = PayrollCalculator::compute(emp(['basic_salary_minor' => 60000]), HrBaseline::GHANA_2024,
        ['working_days' => 22, 'loans' => [['id' => 'a', 'monthly_minor' => 100000, 'balance_minor' => 100000], ['id' => 'b', 'monthly_minor' => 5000, 'balance_minor' => 5000]]]);
    expect($loan['net_minor'])->toBe(0)->and($loan['loan_deductions'])->toHaveCount(1)->and($loan['loans_minor'])->toBe(60000 - 3300 - $loan['paye_minor']);
});
