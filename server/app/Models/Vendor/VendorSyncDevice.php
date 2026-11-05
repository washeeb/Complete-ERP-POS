<?php

namespace App\Models\Vendor;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A copy of the SES Licensing desktop app allowed to sync with this licence server. */
class VendorSyncDevice extends Model
{
    use HasUuids;

    protected $table = 'vendor_sync_devices';

    protected $guarded = [];

    public const STATUSES = ['pending' => 'Waiting for the app', 'active' => 'Active', 'revoked' => 'Revoked'];

    protected $hidden = ['enrol_hash'];

    protected function casts(): array
    {
        return ['enrol_expires_at' => 'datetime', 'registered_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
