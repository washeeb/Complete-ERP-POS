<?php

namespace App\Models\Vendor;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** An add-on to a plan (extra branch, till, users, a module or feature), priced per unit. */
class VendorAddon extends Model
{
    use HasUuids;

    protected $table = 'vendor_addons';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['modules' => 'array', 'features' => 'array', 'is_active' => 'boolean', 'price_minor' => 'integer'];
    }
}
