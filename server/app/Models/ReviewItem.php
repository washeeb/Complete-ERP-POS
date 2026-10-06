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
