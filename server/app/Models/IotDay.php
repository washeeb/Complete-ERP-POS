<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A sensor's day: lowest, highest and average temperature, humidity range, readings and minutes out of range. */
class IotDay extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['day' => 'date', 'min_temp' => 'decimal:1', 'max_temp' => 'decimal:1', 'sum_temp' => 'decimal:1', 'readings' => 'integer', 'out_minutes' => 'integer'];
    }

    public function average(): ?float
    {
        return $this->readings > 0 && $this->min_temp !== null ? round((float) $this->sum_temp / $this->readings, 1) : null;
    }
}
