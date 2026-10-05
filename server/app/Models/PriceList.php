<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['code', 'name', 'currency_code', 'prices_include_tax', 'is_default', 'pos_selectable', 'branch_id', 'is_active'];

    protected function casts(): array
    {
        return ['prices_include_tax' => 'boolean', 'is_default' => 'boolean', 'pos_selectable' => 'boolean', 'is_active' => 'boolean'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
