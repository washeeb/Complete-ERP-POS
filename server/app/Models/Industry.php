<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use App\Domain\Licensing\LicenseManager;
use App\Models\BranchModule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An industry of the company (Pharmacy, Grocery, …): its branches, its own catalogue and prices, its own dashboard. */
class Industry extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['code', 'name', 'module_key', 'legal_entity_id', 'sort', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort' => 'integer'];
    }

    /** Active industries whose module the licence covers (pickers for branches, products and price lists). */
    public function scopeLicensed(Builder $q): Builder
    {
        $modules = app(LicenseManager::class)->status()['modules'];

        return $q->where('is_active', true)->when($modules !== null, fn ($q) => $q->whereIn('module_key', $modules));
    }

    /** Licensed and installed: the module is switched on at one of the company's branches (switcher, dashboards). */
    public function scopeInUse(Builder $q): Builder
    {
        return $q->licensed()->whereIn('module_key', BranchModule::where('status', 'enabled')->select('module_key'));
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }
}
