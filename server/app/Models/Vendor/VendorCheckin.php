<?php

namespace App\Models\Vendor;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A daily check-in from a customer installation (read only). */
class VendorCheckin extends Model
{
    use HasUuids;

    protected $table = 'vendor_checkins';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['report' => 'array', 'tampered' => 'boolean', 'created_at' => 'datetime'];
    }
}
