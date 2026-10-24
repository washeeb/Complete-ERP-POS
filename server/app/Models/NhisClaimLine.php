<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One sale's NHIS part on a claim (a return's comes off as a negative line). */
class NhisClaimLine extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(SalePayment::class, 'sale_payment_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
