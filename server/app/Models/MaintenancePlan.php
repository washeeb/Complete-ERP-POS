<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Servicing that comes round: every so many days, or every so many hours / km on the asset's meter. */
class MaintenancePlan extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['checklist' => 'array', 'last_done_on' => 'date', 'next_due_on' => 'date', 'is_active' => 'boolean', 'every_meter' => 'decimal:1', 'last_meter' => 'decimal:1'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MaintenanceAsset::class, 'asset_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** "Every 90 days", "Every 250 hours", "Every 90 days or 250 hours" */
    public function describe(): string
    {
        $unit = $this->asset?->meter_unit ?? 'hours';
        $parts = array_filter([$this->every_days ? "{$this->every_days} days" : null, $this->every_meter ? rtrim(rtrim((string) $this->every_meter, '0'), '.')." {$unit}" : null]);

        return 'Every '.implode(' or ', $parts);
    }
}
