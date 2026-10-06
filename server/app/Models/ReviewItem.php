<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewItem extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const TYPES = [
        'offline_price' => 'Sold offline at a different price',
        'grant_expired' => 'Sold after the offline sign-in expired',
        'grant_revoked' => 'Sold by a person or device later revoked',
        'clock_skew' => 'Till clock was wrong',
        'negative_stock' => 'Stock went below zero after syncing',
        'sync_conflict' => 'Sync conflict',
        'sync_failed' => 'Change from another node could not be applied',
        'oversell' => 'Online order larger than available stock',
        'channel_total' => 'Store total differs from the items',
        'channel_failed' => 'Could not update an online store',
        'webhook_failed' => 'Store notification could not be processed',
        'transfer_short' => 'Transfer arrived with a different quantity',
        'grn_held' => 'Delivery above the over-delivery tolerance',
        'credit_hold' => 'Customer order on credit hold',
        'credit_over_limit' => 'Credit sale over the customer’s limit',
        'customer_conflict' => 'Customer edited in two places',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'resolved_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
