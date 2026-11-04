<?php

namespace App\Models\Vendor;

use App\Models\Vendor\Concerns\Syncable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A licence package: default limits, modules, features, length and price. */
class VendorPlan extends Model
{
    use HasUuids, Syncable;

    public const SYNC_ENTITY = 'plan';

    public const SYNC_FIELDS = ['code', 'name', 'description', 'branches', 'devices', 'users', 'modules', 'features', 'months', 'price_minor', 'currency', 'is_active'];

    protected $table = 'vendor_plans';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['modules' => 'array', 'features' => 'array', 'is_active' => 'boolean', 'price_minor' => 'integer', 'months' => 'integer'];
    }
}
