<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's membership of a plan, invoiced every period until cancelled. */
class Membership extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'starts_on' => 'date', 'next_bill_on' => 'date', 'ends_on' => 'date'];
    }

    public const STATUSES = ['active' => 'Active', 'paused' => 'Paused', 'cancelled' => 'Cancelled'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
