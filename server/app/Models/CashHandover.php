<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The cashier's day handed to the income controller, who counts it blind again. */
class CashHandover extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'handed_at' => 'datetime', 'received_at' => 'datetime',
            'declared_minor' => 'integer', 'controller_count_minor' => 'integer', 'controller_denoms' => 'array'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(VaultSession::class, 'handover_id');
    }
}
