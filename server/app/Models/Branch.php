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
        return ['is_hq' => 'boolean', 'is_active' => 'boolean', 'settings' => 'array', 'archived_at' => 'datetime', 'archived_children' => 'array'];
    }

    /**
     * Branches to choose from: those in use, plus any already on the record being edited, so an older record still
     * shows its branch after it was archived. $label: name, code or both ("PHAOSU · Pharmacy Osu").
     */
    public static function choices(mixed $keep = null, string $label = 'name'): array
    {
        $keep = array_values(array_filter((array) $keep, fn ($v) => is_string($v) && $v !== ''));

        return self::query()->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($q) => $q->orWhereIn('id', $keep)))
            ->orderBy('code')->get()
            ->mapWithKeys(fn (self $b) => [$b->id => match ($label) {
                'code' => $b->code, 'both' => "{$b->code} · {$b->name}", default => $b->name,
            }.($b->archived_at ? ' (archived)' : '')])->all();
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
