<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A maintenance work order: planned servicing, a breakdown repair or a visit to a customer's equipment. */
class MaintenanceOrder extends Model
{
    use BelongsToTenant, HasUuids;

    public const KINDS = ['planned' => 'Planned servicing', 'breakdown' => 'Breakdown repair', 'visit' => 'Customer visit'];

    public const STATUSES = ['open' => 'To do', 'working' => 'Being worked on', 'waiting' => 'Waiting for parts', 'done' => 'Done', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'due_on' => 'date', 'down_from' => 'datetime', 'down_until' => 'datetime', 'started_at' => 'datetime', 'done_at' => 'datetime',
            'labour_minutes' => 'integer', 'hourly_cost_minor' => 'integer', 'parts_minor' => 'integer', 'outside_minor' => 'integer', 'chargeable' => 'boolean',
            'meter_reading' => 'decimal:1'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MaintenanceAsset::class, 'asset_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'plan_id');
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(MaintenancePart::class, 'order_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'working', 'waiting'], true);
    }

    public function labourMinor(): int
    {
        return (int) round($this->labour_minutes * $this->hourly_cost_minor / 60);
    }

    public function totalMinor(): int
    {
        return $this->parts_minor + $this->outside_minor + $this->labourMinor();
    }

    /** Minutes the asset was out of use (so far, while still down). */
    public function downtimeMinutes(): int
    {
        return $this->down_from ? (int) $this->down_from->diffInMinutes($this->down_until ?? now()) : 0;
    }
}
