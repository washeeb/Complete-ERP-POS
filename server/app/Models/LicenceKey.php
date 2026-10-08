<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A software licence key in stock, handed out when one unit of its product is sold. Shown only to people who manage keys, and on the receipt. */
class LicenceKey extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sold_at' => 'datetime'];
    }

    public const STATUSES = ['available' => 'Available', 'sold' => 'Sold', 'returned' => 'Returned', 'void' => 'Void'];

    protected $hidden = ['licence_key'];
}
