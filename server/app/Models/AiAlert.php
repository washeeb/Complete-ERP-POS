<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Something the insights found that someone should look at. Node-local (recomputed where it runs), not synced. */
class AiAlert extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['open' => 'Open', 'dismissed' => 'Dismissed', 'resolved' => 'Resolved'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'handled_at' => 'datetime'];
    }
}
