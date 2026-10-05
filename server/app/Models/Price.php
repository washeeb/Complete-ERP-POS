<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Price extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['price_list_id', 'product_id', 'product_uom_id', 'min_qty', 'amount_minor', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['min_qty' => 'decimal:3', 'amount_minor' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
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
