<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Which store product/variant a product unit is, once pushed. */
class ChannelProduct extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_pushed_at' => 'datetime'];
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
