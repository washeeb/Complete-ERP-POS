<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Something the business owns and uses for years; depreciated straight-line each month (owner decision 2026-10-13). */
class FixedAsset extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['acquired_on' => 'date', 'disposed_on' => 'date', 'cost_minor' => 'integer', 'residual_minor' => 'integer', 'life_months' => 'integer',
            'opening_depreciation_minor' => 'integer', 'disposal_proceeds_minor' => 'integer'];
    }

    public function assetClass(): BelongsTo
    {
        return $this->belongsTo(AssetClass::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
