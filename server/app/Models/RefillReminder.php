<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A refill reminder for a medicine sold to a named customer (Customers → Refill reminders). */
class RefillReminder extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['waiting' => 'Waiting', 'sent' => 'Sent', 'bought' => 'Bought again', 'cancelled' => 'Cancelled',
        'no_contact' => 'No phone or consent', 'past' => 'Too late to remind'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['runs_out_on' => 'date', 'remind_on' => 'date', 'sent_at' => 'datetime', 'qty_base' => 'decimal:3'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
