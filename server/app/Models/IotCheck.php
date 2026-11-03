<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A person's signed check of a fridge or room: what its own display showed, and whether all was well. */
class IotCheck extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'seen_temp' => 'decimal:1', 'ok' => 'boolean'];
    }
}
