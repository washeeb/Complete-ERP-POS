<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** What one sale (or return) of consignment stock owes its consignor, until settled by a supplier bill. Append-only. */
class ConsignmentSale extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'net_minor' => 'integer', 'share_minor' => 'integer', 'occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
