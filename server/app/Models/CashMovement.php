<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Cash moved from one cash account to another (append-only; the database refuses changes). */
class CashMovement extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'occurred_at' => 'datetime'];
    }
}
