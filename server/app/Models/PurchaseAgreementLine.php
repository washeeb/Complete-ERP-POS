<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseAgreementLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['max_qty' => 'decimal:3', 'unit_cost_minor' => 'integer'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(PurchaseAgreement::class, 'purchase_agreement_id');
    }
}
