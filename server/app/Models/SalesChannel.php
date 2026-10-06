<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An online sales channel: the built-in storefront, a WooCommerce or Shopify store, or a generic REST receiver. */
class SalesChannel extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['type', 'name', 'branch_id', 'warehouse_id', 'price_list_id', 'safety_stock', 'base_url', 'status', 'settings'];

    protected $hidden = ['credentials', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'webhook_secret' => 'encrypted',
            'settings' => 'array',
            'safety_stock' => 'decimal:3',
            'last_reconciled_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ChannelProduct::class, 'channel_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(ChannelJob::class, 'channel_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SalesOrder::class, 'channel_id');
    }
}
