<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One charge of a landed cost (e.g. "Maersk freight, BL 4471", GHS 3,000). */
class LandedCostCharge extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
