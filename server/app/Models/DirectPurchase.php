<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier's invoice with no purchase order. See App\Domain\Purchasing\DirectPurchases. */
class DirectPurchase extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const SETTLEMENTS = ['paid' => 'Paid on delivery', 'credit' => 'On credit (pay later)', 'consignment' => 'Consignment (pay when sold)'];

    public const STATUSES = ['draft' => 'Draft', 'submitted' => 'Waiting for approval', 'posted' => 'Posted', 'cancelled' => 'Cancelled'];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_on' => 'date', 'prices_include_tax' => 'boolean', 'attachments' => 'array', 'net_minor' => 'integer', 'tax_minor' => 'integer',
            'total_minor' => 'integer', 'printed_total_minor' => 'integer', 'submitted_at' => 'datetime', 'posted_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DirectPurchaseLine::class)->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function supplierBill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class);
    }

    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['draft', 'submitted'], true);
    }
}
