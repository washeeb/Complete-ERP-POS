<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A rep's call on a customer, with the outcome and GPS position. Append-only. */
class Visit extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const OUTCOMES = ['sold' => 'Sold', 'order' => 'Took an order', 'no_order' => 'No order', 'closed' => 'Shop closed'];

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime', 'lat' => 'decimal:6', 'lng' => 'decimal:6', 'accuracy_m' => 'integer'];
    }
}
