<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One unit of a serial-numbered product (Electronics). Written only by the stock ledger (SerialRegistry). */
class SerialNumber extends Model
{
    use BelongsToTenant;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public const STATUSES = ['in_stock' => 'In stock', 'in_transit' => 'In transit', 'sold' => 'Sold', 'returned_to_supplier' => 'Returned to supplier', 'written_off' => 'Written off'];

    protected function casts(): array
    {
        return ['sold_at' => 'datetime', 'warranty_until' => 'date'];
    }

    public function inWarranty(): bool
    {
        return $this->warranty_until !== null && $this->warranty_until->gte(now()->startOfDay());
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
