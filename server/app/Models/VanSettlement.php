<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** End-of-day settlement of a van: stock counted against the ledger, unsold stock unloaded, cash handed in. */
class VanSettlement extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['counting' => 'Counting', 'submitted' => 'Waiting for approval', 'posted' => 'Settled', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'posted_at' => 'datetime', 'sales_minor' => 'integer', 'cash_expected_minor' => 'integer',
            'cash_counted_minor' => 'integer', 'stock_variance_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(VanSettlementLine::class);
    }

    public function van(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'van_warehouse_id');
    }
}
