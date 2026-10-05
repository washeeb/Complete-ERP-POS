<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only (DB trigger). Written only by App\Domain\Inventory\StockLedger. */
class StockMovement extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['qty_base' => 'decimal:3', 'qty_entered' => 'decimal:3', 'factor' => 'decimal:6',
            'occurred_at' => 'datetime', 'received_at' => 'datetime', 'unit_cost_minor' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }
}
