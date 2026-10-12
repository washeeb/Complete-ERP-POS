<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A branch asking another for stock. Between two industries both sides approve before anything is sent. */
class StockRequest extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['requested' => 'Requested', 'approved' => 'Approved', 'supplied' => 'Sent', 'received' => 'Received',
        'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cross_industry' => 'boolean', 'requester_approved_at' => 'datetime', 'supplier_approved_at' => 'datetime',
            'supplied_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockRequestLine::class)->orderBy('line_no');
    }

    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /** Waiting for: 'requester' / 'supplier' approval (between industries), 'supply', or null when done. */
    public function waitingFor(): ?string
    {
        return match (true) {
            $this->status === 'requested' && $this->cross_industry && ! $this->requester_approved_at => 'requester',
            $this->status === 'requested' && $this->cross_industry => 'supplier',
            in_array($this->status, ['requested', 'approved'], true) => 'supply',
            default => null,
        };
    }
}
