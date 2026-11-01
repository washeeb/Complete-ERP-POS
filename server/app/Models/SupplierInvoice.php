<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An invoice a supplier sent through the supplier portal, waiting to be entered as a supplier bill (or turned back). */
class SupplierInvoice extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['received' => 'To enter', 'entered' => 'Entered as a bill', 'rejected' => 'Turned back'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'handled_at' => 'datetime', 'amount_minor' => 'integer'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }
}
