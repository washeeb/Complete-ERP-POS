<?php

namespace App\Models;

use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A piece of equipment that is looked after: the company's own (generator, fridge, van…) or a customer's. */
class MaintenanceAsset extends Model
{
    use Archivable, BelongsToTenant, HasUuids;

    public const CATEGORIES = ['generator' => 'Generator', 'fridge' => 'Fridge or cold room', 'air_conditioner' => 'Air conditioner', 'vehicle' => 'Vehicle',
        'till' => 'Till, printer or scanner', 'computer' => 'Computer or network', 'machine' => 'Machine', 'building' => 'Building', 'other' => 'Other'];

    public const STATUSES = ['working' => 'Working', 'down' => 'Not working', 'retired' => 'Retired'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['acquired_on' => 'date', 'warranty_until' => 'date', 'contract_until' => 'date', 'meter_reading' => 'decimal:1'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(MaintenancePlan::class, 'asset_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(MaintenanceOrder::class, 'asset_id');
    }

    public function underWarranty(?\DateTimeInterface $on = null): bool
    {
        return $this->warranty_until !== null && $this->warranty_until->endOfDay()->gte($on ?? now());
    }

    public function underContract(?\DateTimeInterface $on = null): bool
    {
        return $this->contract_until !== null && $this->contract_until->endOfDay()->gte($on ?? now());
    }
}
