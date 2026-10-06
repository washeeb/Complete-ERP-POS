<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Goods received note (GRN). Posted ones never change; a mistake is reversed. */
class GoodsReceipt extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['held' => 'Waiting for manager', 'posted' => 'Posted', 'reversed' => 'Reversed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'net_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('line_no');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
