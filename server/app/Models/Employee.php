<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person on the payroll. Salary and bank details are visible only to HR and payroll. */
class Employee extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const TYPES = ['permanent' => 'Permanent', 'contract' => 'Fixed-term contract', 'casual' => 'Casual'];

    public const PAY_METHODS = ['bank' => 'Bank transfer', 'momo' => 'Mobile money', 'cash' => 'Cash'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date', 'hire_date' => 'date', 'contract_end' => 'date', 'termination_date' => 'date', 'overtime_junior' => 'boolean',
            'ssnit_exempt' => 'boolean', 'basic_salary_minor' => 'integer', 'transport_allowance_minor' => 'integer', 'meal_allowance_minor' => 'integer',
            'welfare_minor' => 'integer', 'tier3_bp' => 'integer', 'overtime_rate_bp' => 'integer', 'takes_appointments' => 'boolean', 'commission_bp' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
