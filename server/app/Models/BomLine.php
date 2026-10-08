<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BomLine extends Model
{
    use HasUuids;

    public const KINDS = ['material' => 'Material', 'by_product' => 'By-product'];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'scrap_bp' => 'integer', 'backflush' => 'boolean'];
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
