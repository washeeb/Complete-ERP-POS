<?php

namespace App\Domain\Hr;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Starter HR data for a company (only when it has none): Ghana leave types (owner decision 2026-10-14) and the
 * Ghana payroll profile. NOT VERIFIED: the profile must be checked by an accountant against current GRA PAYE bands
 * and SSNIT rates (HR → Payroll profile → mark as verified) before the first real payroll.
 */
final class HrBaseline
{
    /** [code, name, working days a year (null = no fixed allowance), paid, needs a document] */
    public const LEAVE_TYPES = [
        ['ANNUAL', 'Annual leave', 15, true, false],
        ['SICK', 'Sick leave', null, true, true],
        ['MATERNITY', 'Maternity leave (12 weeks)', 60, true, true],
        ['PATERNITY', 'Paternity leave', 5, true, false],
        ['UNPAID', 'Unpaid leave', null, false, false],
    ];

    /**
     * Ghana, from 1 January 2024 (as configured at build time; accountant to verify).
     * PAYE bands are monthly widths in pesewas with the rate in basis points; the last band has no upper limit.
     */
    public const GHANA_2024 = [
        'paye_bands' => [[49000, 0], [11000, 500], [13000, 1000], [316667, 1750], [1600000, 2500], [3052000, 3000], [null, 3500]],
        'ssnit' => ['employee_bp' => 550, 'employer_bp' => 1300, 'tier1_bp' => 1350, 'tier2_bp' => 500],
        'tier3_relief_bp' => 1650, // employee Tier 3 contributions relieved from tax up to this % of basic
        'overtime_junior' => ['threshold_bp' => 5000, 'low_bp' => 500, 'high_bp' => 1000], // ≤ 50 % of basic at 5 %, the rest at 10 %
        'allowances_taxable' => true,
        'standard_day_hours' => 8,
    ];

    public static function ensure(Tenant $tenant): void
    {
        DB::transaction(function () use ($tenant) {
            $row = fn () => ['tenant_id' => $tenant->id, 'origin_node' => (string) config('erp.node.id'), 'version' => 1, 'created_at' => now(), 'updated_at' => now()];
            if (! DB::table('leave_types')->where('tenant_id', $tenant->id)->exists()) {
                foreach (self::LEAVE_TYPES as [$code, $name, $days, $paid, $doc]) {
                    DB::table('leave_types')->insert($row() + ['id' => (string) Str::uuid7(), 'code' => $code, 'name' => $name, 'days_per_year' => $days,
                        'paid' => $paid, 'needs_document' => $doc, 'is_active' => true]);
                }
            }
            if (! DB::table('payroll_profiles')->where('tenant_id', $tenant->id)->exists() && $tenant->country_code === 'GH') {
                DB::table('payroll_profiles')->insert($row() + ['id' => (string) Str::uuid7(), 'code' => 'GH-2024', 'name' => 'Ghana PAYE and SSNIT (2024 bands)',
                    'effective_from' => '2024-01-01', 'rules' => json_encode(self::GHANA_2024)]);
            }
        });
    }
}
