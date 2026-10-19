<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The document that travels with goods: what, from where, to whom, by which vehicle and driver, and proof it arrived. */
class Waybill extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const KINDS = ['delivery' => 'Delivery to a customer', 'transfer' => 'Transfer between branches', 'supplier_return' => 'Return to a supplier',
        'inbound' => 'Goods from a supplier (their waybill)'];

    public const SOURCES = ['delivery' => 'delivery', 'transfer' => 'stock_transfer', 'supplier_return' => 'supplier_return', 'inbound' => 'goods_receipt'];

    public const STATUSES = ['prepared' => 'Prepared', 'dispatched' => 'On the way', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected $hidden = ['confirm_token'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'dispatched_at' => 'datetime', 'delivered_at' => 'datetime', 'packages' => 'integer', 'weight_kg' => 'decimal:2'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
