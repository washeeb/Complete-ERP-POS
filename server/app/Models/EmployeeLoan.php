<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** A staff loan or salary advance, repaid by monthly payroll deductions. */
class EmployeeLoan extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const KINDS = ['advance' => 'Salary advance', 'loan' => 'Staff loan'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['first_month' => 'date', 'issued_on' => 'date', 'principal_minor' => 'integer', 'monthly_minor' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function repaidMinor(): int
    {
        return (int) DB::table('loan_repayments')->where('employee_loan_id', $this->id)->sum('amount_minor');
    }
}
