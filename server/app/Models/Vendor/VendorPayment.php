<?php

namespace App\Models\Vendor;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Money received from a customer against an invoice. */
class VendorPayment extends Model
{
    use HasUuids;

    protected $table = 'vendor_payments';

    protected $guarded = [];

    public const METHODS = ['bank' => 'Bank transfer (ABSA)', 'momo' => 'MTN MoMo', 'telecel' => 'Telecel Cash', 'cash' => 'Cash', 'card' => 'Card'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'amount_minor' => 'integer'];
    }
}
