<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A used item a customer handed in as part-payment on a sale; it went into stock at the value given. */
class TradeIn extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value_minor' => 'integer', 'created_at' => 'datetime'];
    }
}
