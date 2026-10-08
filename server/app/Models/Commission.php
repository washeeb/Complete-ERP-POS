<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Commission a staff member earned on one sale line (negative for a return). Append-only; paid through payroll. */
class Commission extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['base_minor' => 'integer', 'rate_bp' => 'integer', 'amount_minor' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
