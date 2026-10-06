<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePayment extends Model
{
    use HasUuids;

    public const METHODS = ['cash' => 'Cash', 'momo' => 'Mobile money', 'card' => 'Card (standalone terminal)', 'account' => 'On account (credit)'];

    public const MOMO_PROVIDERS = ['mtn' => 'MTN MoMo', 'telecel' => 'Telecel Cash', 'at' => 'AT Money'];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'tendered_minor' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
