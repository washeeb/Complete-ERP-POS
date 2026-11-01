<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Freight, duty and clearing spread over deliveries: into stock still on hand, and cost of sales for what was sold. */
class LandedCost extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const METHODS = ['value' => 'By value (the usual way)', 'quantity' => 'By quantity (pieces)'];

    public const KINDS = ['freight' => 'Freight / transport', 'duty' => 'Import duty and levies', 'clearing' => 'Clearing and port charges', 'insurance' => 'Insurance', 'other' => 'Other'];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'total_minor' => 'integer', 'to_stock_minor' => 'integer', 'to_cogs_minor' => 'integer'];
    }

    public function charges(): HasMany
    {
        return $this->hasMany(LandedCostCharge::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LandedCostLine::class);
    }
}
