<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Money paid to a supplier; settles the oldest open bills first. */
class SupplierPayment extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const METHODS = ['bank' => 'Bank transfer', 'momo' => 'Mobile money', 'cash' => 'Cash', 'cheque' => 'Cheque'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'amount_minor' => 'integer', 'wht_minor' => 'integer', 'wht_rate_bp' => 'integer', 'wht_base_minor' => 'integer'];
    }

    /** What actually left the bank or till: the amount less the withholding tax kept back. */
    public function cashMinor(): int
    {
        return $this->amount_minor - (int) $this->wht_minor;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
