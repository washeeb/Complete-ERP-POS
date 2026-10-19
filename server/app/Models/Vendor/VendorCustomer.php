<?php

namespace App\Models\Vendor;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A SyncLytics customer (licence server only). */
class VendorCustomer extends Model
{
    use HasUuids;

    protected $table = 'vendor_customers';

    protected $guarded = [];

    public function licenses(): HasMany
    {
        return $this->hasMany(VendorLicense::class, 'customer_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(VendorInvoice::class, 'customer_id');
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
