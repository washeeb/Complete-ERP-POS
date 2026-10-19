<?php

namespace App\Models\Vendor;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An invoice to a customer for a licence or anything else. */
class VendorInvoice extends Model
{
    use HasUuids;

    protected $table = 'vendor_invoices';

    protected $guarded = [];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(VendorCustomer::class, 'customer_id');
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(VendorLicense::class, 'license_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VendorPayment::class, 'invoice_id')->orderBy('received_on');
    }

    public function paidMinor(): int
    {
        return (int) $this->payments()->sum('amount_minor');
    }

    public function balanceMinor(): int
    {
        return $this->status === 'void' ? 0 : $this->total_minor - $this->paidMinor();
    }

    protected function casts(): array
    {
        return ['lines' => 'array', 'issued_on' => 'date', 'due_on' => 'date', 'total_minor' => 'integer'];
    }
}
