<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class TaxGroup extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['code', 'name'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function rates(): BelongsToMany
    {
        return $this->belongsToMany(TaxRate::class, 'tax_group_rates')->orderBy('sort');
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }
}
