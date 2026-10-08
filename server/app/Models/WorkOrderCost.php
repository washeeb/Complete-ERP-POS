<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Labour and overhead charged to a work order, or the cost left over when it closes. Append-only. */
class WorkOrderCost extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'minutes' => 'decimal:2', 'occurred_at' => 'datetime'];
    }
}
