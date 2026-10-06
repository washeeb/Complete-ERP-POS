<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A product as a supplier sells it: the purchase unit, their code, the last cost and minimum order. */
class SupplierProduct extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['supplier_id', 'product_id', 'product_uom_id', 'supplier_sku', 'last_cost_minor', 'min_order_qty', 'is_preferred'];

    protected function casts(): array
    {
        return ['last_cost_minor' => 'integer', 'min_order_qty' => 'decimal:3', 'is_preferred' => 'boolean'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class, 'product_uom_id');
    }
}
