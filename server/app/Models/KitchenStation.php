<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Where food or drink is prepared at a branch (kitchen, bar, grill); products name their station by code. */
class KitchenStation extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['auto_print' => 'boolean', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
