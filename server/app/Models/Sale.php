<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Posted sale. Its id is generated on the till (UUIDv7) and is the idempotency key. */
class Sale extends Model
{
    use BelongsToTenant, HasOriginNode;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'prices_include_tax' => 'boolean', 'tax_breakdown' => 'array', 'occurred_at' => 'datetime',
            'received_at' => 'datetime', 'device_seq' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer',
            'total_minor' => 'integer', 'paid_minor' => 'integer', 'change_minor' => 'integer', 'discount_minor' => 'integer',
            'sale_discount_bp' => 'integer', 'points_earned' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class)->orderBy('line_no');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
