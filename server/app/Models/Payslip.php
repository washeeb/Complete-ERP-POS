<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['employee' => 'array', 'lines' => 'array', 'loan_deductions' => 'array', 'overtime_hours' => 'float', 'basic_minor' => 'integer', 'gross_minor' => 'integer',
            'taxable_minor' => 'integer', 'paye_minor' => 'integer', 'ssnit_employee_minor' => 'integer', 'ssnit_employer_minor' => 'integer', 'tier1_minor' => 'integer',
            'tier2_minor' => 'integer', 'tier3_minor' => 'integer', 'welfare_minor' => 'integer', 'loans_minor' => 'integer', 'net_minor' => 'integer', 'commission_minor' => 'integer'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }
}
