<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stock count (cycle or full; blind by default). Posting turns the differences into count movements. */
class StockCount extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['counting' => 'Counting', 'submitted' => 'Waiting for approval', 'posted' => 'Posted', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['blind' => 'boolean', 'started_at' => 'datetime', 'submitted_at' => 'datetime', 'posted_at' => 'datetime', 'variance_value_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(Bin::class);
    }
}
