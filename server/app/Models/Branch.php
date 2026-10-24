<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use App\Security\Authorizer;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    protected static function booted(): void
    {
        static::saved(fn () => app(Authorizer::class)->forgetBranches()); // its company / industry, remembered for permission checks
    }

    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['legal_entity_id', 'industry_id', 'code', 'name', 'is_hq', 'address', 'phone', 'timezone', 'is_active'];

    protected function casts(): array
    {
        return ['is_hq' => 'boolean', 'is_active' => 'boolean', 'settings' => 'array'];
    }

    public function industry(): BelongsTo
    {
        return $this->belongsTo(Industry::class);
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
