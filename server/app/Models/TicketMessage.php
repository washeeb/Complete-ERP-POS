<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message on a ticket: from the customer (in), to them (out), or a staff note. */
class TicketMessage extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['files' => 'array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
