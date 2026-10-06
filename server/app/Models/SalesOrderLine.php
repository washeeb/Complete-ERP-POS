<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'factor' => 'decimal:6', 'tax_breakdown' => 'array', 'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productUom(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class);
    }
}
