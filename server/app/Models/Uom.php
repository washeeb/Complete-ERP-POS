<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Uom extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['code', 'name', 'decimals'];

    /** Set when this unit was a duplicate merged into another (kept for the stock history, no longer offered). */
    public function mergedInto(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    protected function casts(): array
    {
        return ['decimals' => 'integer'];
    }
}
