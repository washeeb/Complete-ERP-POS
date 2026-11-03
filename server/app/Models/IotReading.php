<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One reading from a sensor (kept: the temperature log). */
class IotReading extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'datetime', 'temperature' => 'decimal:1', 'humidity' => 'integer', 'power_on' => 'boolean', 'door_open' => 'boolean', 'battery' => 'integer'];
    }
}
