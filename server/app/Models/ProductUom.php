<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductUom extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['product_id', 'uom_id', 'factor', 'is_base', 'is_sellable', 'is_purchasable'];

    protected function casts(): array
    {
        return ['factor' => 'decimal:6', 'is_base' => 'boolean', 'is_sellable' => 'boolean', 'is_purchasable' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(Barcode::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }
}
