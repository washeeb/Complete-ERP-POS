<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A table booking, with an optional deposit held until it is used on the bill, refunded or forfeited. */
class Reservation extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    public const STATUSES = ['booked' => 'Booked', 'seated' => 'Seated', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'reminder_sent_at' => 'datetime', 'party_size' => 'integer', 'duration_minutes' => 'integer',
            'deposit_minor' => 'integer', 'deposit_used_minor' => 'integer'];
    }

    public function depositLeft(): int
    {
        return in_array($this->deposit_status, ['held', 'used'], true) ? $this->deposit_minor - $this->deposit_used_minor : 0;
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
