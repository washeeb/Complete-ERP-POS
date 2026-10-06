<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A customer quotation that becomes an order (B2B / wholesale). Goods leave on delivery notes; each delivery raises its invoice. */
class CustomerOrder extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['quotation' => 'Quotation', 'on_hold' => 'On credit hold', 'confirmed' => 'Confirmed', 'partially_delivered' => 'Partly delivered', 'delivered' => 'Delivered', 'lost' => 'Quotation lost', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['valid_until' => 'date', 'released_at' => 'datetime', 'confirmed_at' => 'datetime', 'closed_at' => 'datetime', 'prices_include_tax' => 'boolean', 'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CustomerOrderLine::class)->orderBy('line_no');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function warehouseModel(): Warehouse
    {
        return $this->warehouse ?? Warehouse::findOrFail($this->warehouse_id);
    }

    public function isDeliverable(): bool
    {
        return in_array($this->status, ['confirmed', 'partially_delivered'], true);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
