<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A fashion style: one design in a range of sizes and colours; each size × colour is its own product (variant). */
class Style extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sizes' => 'array', 'colours' => 'array', 'is_active' => 'boolean', 'price_minor' => 'integer'];
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
