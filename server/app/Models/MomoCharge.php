<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment request sent to a customer's phone from a till (System → Mobile money → Requests). */
class MomoCharge extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['pending' => 'Waiting for the customer', 'otp' => 'Waiting for the code', 'success' => 'Paid', 'failed' => 'Not paid', 'expired' => 'Timed out'];

    /** How long the till waits for the customer to approve before the request counts as timed out. */
    public const WAIT_SECONDS = 180;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
