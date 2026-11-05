<?php

namespace App\Models\Vendor;

use App\Models\Vendor\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A SyncLytics customer (licence server only). */
class VendorCustomer extends Model
{
    use HasUuids, Syncable;

    public const SYNC_ENTITY = 'customer';

    public const SYNC_FIELDS = ['code', 'name', 'legal_name', 'contact_name', 'email', 'phone', 'whatsapp', 'address', 'tin', 'industry', 'notes', 'is_active'];

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
