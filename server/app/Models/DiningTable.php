<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A table on a branch's floor plan. */
class DiningTable extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'seats' => 'integer', 'pos_x' => 'integer', 'pos_y' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
