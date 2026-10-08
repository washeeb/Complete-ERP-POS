<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A booked service with a staff member, paid at the till when done. */
class Appointment extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'reminder_sent_at' => 'datetime'];
    }

    public const STATUSES = ['booked' => 'Booked', 'arrived' => 'Arrived', 'done' => 'Done', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];

    public const OPEN = ['booked', 'arrived'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
