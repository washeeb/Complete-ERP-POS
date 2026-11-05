<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DirectPurchaseLine extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_price_minor' => 'integer', 'unit_cost_minor' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer',
            'tax_breakdown' => 'array', 'expires_on' => 'date', 'new_price_minor' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class, 'product_uom_id');
    }

    public function sellUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class, 'sell_uom_id');
    }
}
