<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A sensor out of range (or silent), from when it started until someone says what was done. */
class IotAlarm extends Model
{
    use BelongsToTenant, HasUuids;

    public const KINDS = ['too_warm' => 'Too warm', 'too_cold' => 'Too cold', 'humidity' => 'Humidity out of range', 'power' => 'Power off', 'door' => 'Door left open',
        'silent' => 'Sensor silent'];

    public const ACTIONS = ['checked_ok' => 'Checked: stock is fine', 'moved' => 'Stock moved to another fridge', 'discarded' => 'Stock quarantined or discarded',
        'repaired' => 'Fault fixed', 'false_alarm' => 'False alarm (sensor moved, door opened for loading…)', 'other' => 'Other (in the notes)'];

    public const STATUSES = ['open' => 'Waiting for someone', 'acknowledged' => 'Being handled', 'closed' => 'Closed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime', 'last_alert_at' => 'datetime', 'escalated_at' => 'datetime', 'acknowledged_at' => 'datetime',
            'worst' => 'decimal:1', 'alerts_sent' => 'integer'];
    }

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(IotSensor::class, 'sensor_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MaintenanceOrder::class, 'maintenance_order_id');
    }

    /** Minutes it lasted (so far, while still going on). */
    public function minutes(): int
    {
        return (int) $this->started_at->diffInMinutes($this->ended_at ?? now());
    }
}
