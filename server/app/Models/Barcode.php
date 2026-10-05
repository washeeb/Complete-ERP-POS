<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Barcode extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['product_id', 'product_uom_id', 'code'];

    protected function casts(): array
    {
        return [];
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
