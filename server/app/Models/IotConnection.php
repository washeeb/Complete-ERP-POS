<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** An account at a sensor cloud (SensorPush, Tuya) that ZionEX reads readings from; its credentials kept encrypted. */
class IotConnection extends Model
{
    use BelongsToTenant, HasUuids;

    public const PROVIDERS = ['sensorpush' => 'SensorPush', 'tuya' => 'Tuya / Smart Life'];

    public const TUYA_REGIONS = ['eu' => 'Europe and Africa (openapi.tuyaeu.com)', 'us' => 'Americas (openapi.tuyaus.com)', 'in' => 'India (openapi.tuyain.com)',
        'cn' => 'China (openapi.tuyacn.com)'];

    protected $guarded = [];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'is_active' => 'boolean', 'last_polled_at' => 'datetime'];
    }
}
