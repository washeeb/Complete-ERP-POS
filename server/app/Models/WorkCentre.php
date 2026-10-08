<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Where production work is done, with its cost per hour (labour and overhead) and daily capacity. */
class WorkCentre extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['hourly_rate_minor' => 'integer', 'hours_per_day' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
