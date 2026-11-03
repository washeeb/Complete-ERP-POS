<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A sensor: on a fridge, cold room, freezer or in a room, or watching the power or a door. */
class IotSensor extends Model
{
    use BelongsToTenant, HasUuids;

    /** kind => [label, lowest, highest °C, lowest, highest humidity %] it starts with */
    public const KINDS = [
        'fridge' => ['Fridge (2–8 °C)', 2.0, 8.0, null, null],
        'cold_room' => ['Cold room (2–8 °C)', 2.0, 8.0, null, null],
        'freezer' => ['Freezer (−25 to −15 °C)', -25.0, -15.0, null, null],
        'room' => ['Room or store (temperature and humidity)', 15.0, 25.0, 30, 65],
        'power' => ['Mains power', null, null, null, null],
        'door' => ['Door', null, null, null, null],
    ];

    public const CONNECTIONS = ['direct' => 'Sends to ZionEX itself', 'sensorpush' => 'SensorPush cloud', 'tuya' => 'Tuya / Smart Life cloud'];

    public const STATUSES = ['waiting' => 'No reading yet', 'ok' => 'In range', 'alarm' => 'Alarm', 'silent' => 'Silent', 'off' => 'Switched off'];

    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['min_temp' => 'decimal:1', 'max_temp' => 'decimal:1', 'last_temp' => 'decimal:1', 'out_since' => 'datetime', 'last_reading_at' => 'datetime',
            'last_power' => 'boolean', 'last_door_open' => 'boolean', 'is_active' => 'boolean', 'min_humidity' => 'integer', 'max_humidity' => 'integer',
            'last_humidity' => 'integer', 'battery' => 'integer', 'delay_minutes' => 'integer', 'silent_minutes' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MaintenanceAsset::class, 'asset_id');
    }

    public function connectionAccount(): BelongsTo
    {
        return $this->belongsTo(IotConnection::class, 'connection_id');
    }

    public function alarms(): HasMany
    {
        return $this->hasMany(IotAlarm::class, 'sensor_id');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(IotCheck::class, 'sensor_id');
    }

    public function measuresTemperature(): bool
    {
        return in_array($this->kind, ['fridge', 'cold_room', 'freezer', 'room'], true);
    }

    /** "2.0 – 8.0 °C" */
    public function range(): string
    {
        $t = $this->min_temp !== null || $this->max_temp !== null ? ($this->min_temp ?? '…').' – '.($this->max_temp ?? '…').' °C' : null;
        $h = $this->min_humidity !== null || $this->max_humidity !== null ? ($this->min_humidity ?? '…').' – '.($this->max_humidity ?? '…').' %' : null;

        return implode(', ', array_filter([$t, $h])) ?: ($this->kind === 'power' ? 'Power on' : 'Door closed');
    }
}
