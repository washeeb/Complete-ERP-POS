<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Stock going back to a supplier (expired, near expiry, damaged, recalled), settled by credit note, replacement, refund or nothing. */
class SupplierReturn extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['submitted' => 'Waiting for approval', 'posted' => 'Sent back', 'cancelled' => 'Cancelled'];

    public const SETTLEMENTS = ['credit_note' => 'Credit note (reduces what we owe)', 'replacement' => 'Replacement stock', 'refund' => 'Cash / MoMo / bank refund',
        'none' => 'No credit (they dispose of it)'];

    public const REASONS = ['expired' => 'Expired', 'near_expiry' => 'Near expiry', 'damaged' => 'Damaged', 'recalled' => 'Recalled by the supplier or FDA', 'other' => 'Other'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['posted_at' => 'datetime', 'settled_at' => 'datetime', 'total_minor' => 'integer', 'refund_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierReturnLine::class)->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
