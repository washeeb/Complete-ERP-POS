<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Goods taken back in the back office, waiting for someone else's approval. See App\Domain\Pos\OfficeReturns. */
class OfficeReturn extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['submitted' => 'Waiting for approval', 'posted' => 'Posted', 'cancelled' => 'Cancelled'];

    public const REFUNDS = ['store_credit' => 'Store credit', 'account' => 'Customer\'s account', 'money' => 'Paid from an account (cash, bank or MoMo)'];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['lines' => 'array', 'total_minor' => 'integer', 'posted_at' => 'datetime'];
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'original_sale_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
