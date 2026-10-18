<?php

namespace App\Models\Vendor;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A licence package: default limits, modules, features, length and price. */
class VendorPlan extends Model
{
    use HasUuids;

    protected $table = 'vendor_plans';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['modules' => 'array', 'features' => 'array', 'is_active' => 'boolean', 'price_minor' => 'integer', 'months' => 'integer'];
    }
}
