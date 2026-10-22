<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = [
        'draft' => 'Draft', 'pending_approval' => 'Waiting for approval', 'approved' => 'Approved',
        'partially_received' => 'Partly received', 'received' => 'Received', 'closed' => 'Closed short', 'cancelled' => 'Cancelled',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expected_on' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'closed_at' => 'datetime', 'sent_at' => 'datetime',
            'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_no');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function isReceivable(): bool
    {
        return in_array($this->status, ['approved', 'partially_received'], true);
    }
}
