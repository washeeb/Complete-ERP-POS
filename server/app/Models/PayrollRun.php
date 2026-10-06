<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One month's payroll: draft (recalculable) → approved (posted to the books) → paid. */
class PayrollRun extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'paid' => 'Paid'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'pay_date' => 'date', 'paid_on' => 'date', 'approved_at' => 'datetime', 'totals' => 'array'];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PayrollProfile::class, 'payroll_profile_id');
    }
}
