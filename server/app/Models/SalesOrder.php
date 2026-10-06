<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An order from an online channel. Stock is reserved when it is accepted and issued when it is fulfilled. */
class SalesOrder extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'ready' => 'Ready for pickup', 'fulfilled' => 'Fulfilled', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'delivery_address' => 'array', 'tax_breakdown' => 'array', 'short_stock' => 'boolean', 'prices_include_tax' => 'boolean',
            'placed_at' => 'datetime', 'fulfilled_at' => 'datetime', 'cancelled_at' => 'datetime',
            'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('line_no');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(SalesChannel::class, 'channel_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'confirmed', 'ready'], true);
    }
}
