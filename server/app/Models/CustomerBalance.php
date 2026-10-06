<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Projection of the customer ledger (sum of entries), moved in the same transaction. */
class CustomerBalance extends Model
{
    use BelongsToTenant;

    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['balance_minor' => 'integer'];
    }
}
