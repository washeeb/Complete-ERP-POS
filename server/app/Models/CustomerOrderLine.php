<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerOrderLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'delivered_qty' => 'decimal:3', 'factor' => 'decimal:6', 'unit_price_minor' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer', 'tax_breakdown' => 'array'];
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
