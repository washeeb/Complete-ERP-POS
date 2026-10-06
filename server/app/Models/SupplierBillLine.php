<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierBillLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tax_breakdown' => 'array', 'unit_cost_minor' => 'integer', 'order_cost_minor' => 'integer', 'received_value_minor' => 'integer',
            'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
