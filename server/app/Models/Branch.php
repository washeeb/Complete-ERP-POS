<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['legal_entity_id', 'code', 'name', 'is_hq', 'address', 'phone', 'timezone', 'is_active'];

    protected function casts(): array
    {
        return ['is_hq' => 'boolean', 'is_active' => 'boolean'];
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function tills(): HasMany
    {
        return $this->hasMany(Till::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(BranchModule::class);
    }
}
