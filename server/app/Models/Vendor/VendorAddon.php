<?php

namespace App\Models\Vendor;

use App\Models\Vendor\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** An add-on to a plan (extra branch, till, users, a module or feature), priced per unit. */
class VendorAddon extends Model
{
    use HasUuids, Syncable;

    public const SYNC_ENTITY = 'addon';

    public const SYNC_FIELDS = ['code', 'name', 'branches', 'devices', 'users', 'modules', 'features', 'price_minor', 'currency', 'is_active'];

    protected $table = 'vendor_addons';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['modules' => 'array', 'features' => 'array', 'is_active' => 'boolean', 'price_minor' => 'integer'];
    }
}
