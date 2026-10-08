<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One session used from a pass (+1), or given back by a return (−1). Append-only. */
class ServicePassUse extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['item' => 'integer', 'sessions' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
