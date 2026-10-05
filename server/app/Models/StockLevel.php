<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Projection of the stock ledger (composite key warehouse + product). Read-only outside StockLedger. */
class StockLevel extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $primaryKey = 'product_id'; // Filament needs a single key; unique together with warehouse_id

    protected $keyType = 'string';

    const CREATED_AT = null;

    protected function casts(): array
    {
        return ['on_hand' => 'decimal:3', 'updated_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** Never save through Eloquent with only product_id as key. */
    protected function setKeysForSaveQuery($query): Builder
    {
        return $query->where('warehouse_id', $this->getAttribute('warehouse_id'))
            ->where('product_id', $this->getAttribute('product_id'));
    }
}
