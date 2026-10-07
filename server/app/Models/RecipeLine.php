<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ingredient of a dish, per one base unit of the dish (deducted from stock when the dish is sold). */
class RecipeLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'ingredient_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class, 'ingredient_uom_id');
    }
}
