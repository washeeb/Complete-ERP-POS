<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money spent by staff (petty cash or out of pocket), approved and then paid. */
class ExpenseClaim extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['submitted' => 'Waiting for approval', 'approved' => 'Approved, to pay', 'paid' => 'Paid', 'rejected' => 'Rejected'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'paid_on' => 'date', 'approved_at' => 'datetime', 'amount_minor' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
