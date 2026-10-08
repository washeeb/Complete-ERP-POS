<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A stock adjustment: several lines that increase, decrease or set stock in one warehouse, approved before posting. */
class StockAdjustment extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Waiting for approval', 'posted' => 'Posted', 'cancelled' => 'Cancelled'];

    public const MODES = ['add' => 'Increase (+)', 'remove' => 'Decrease (−)', 'set' => 'Set to counted quantity'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'posted_at' => 'datetime', 'value_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class)->orderBy('line_no');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
