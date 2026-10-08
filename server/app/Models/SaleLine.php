<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'factor' => 'decimal:6', 'unit_price_minor' => 'integer', 'line_total_minor' => 'integer',
            'net_minor' => 'integer', 'tax_minor' => 'integer', 'tax_breakdown' => 'array', 'promotions' => 'array', 'manual_discount' => 'array', 'serials' => 'array',
            'list_total_minor' => 'integer', 'discount_minor' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
