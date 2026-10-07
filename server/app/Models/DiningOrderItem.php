<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One dish or drink on a dining order, with its modifiers, course and kitchen status. */
class DiningOrderItem extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public const STATUSES = ['held' => 'Waiting for its course', 'fired' => 'In the kitchen', 'ready' => 'Ready', 'served' => 'Served', 'void' => 'Voided'];

    protected function casts(): array
    {
        return ['modifiers' => 'array', 'added_at' => 'datetime', 'fired_at' => 'datetime', 'ready_at' => 'datetime', 'served_at' => 'datetime',
            'course' => 'integer', 'seat' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(DiningOrder::class, 'dining_order_id');
    }
}
