<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Keep a product between min and max (base units) in a warehouse; reorder suggestions top it up to max. */
class ReorderRule extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['warehouse_id', 'product_id', 'min_qty', 'max_qty', 'source'];

    protected function casts(): array
    {
        return ['min_qty' => 'decimal:3', 'max_qty' => 'decimal:3'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
