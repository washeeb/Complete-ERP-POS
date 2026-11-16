<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A till asking a manager for a yes (see App\Domain\Pos\TillApprovals). */
class TillApproval extends Model
{
    use BelongsToTenant, HasUuids;

    /** What a till may ask for, and the permission (and limit) the approver needs: the same as approving by PIN. */
    public const KINDS = [
        'return' => ['Return or refund', 'pos.return.no_receipt', null],
        'discount' => ['Discount or price change', 'pos.discount', 'discount_bp'],
        'void' => ['Remove items from a sale', 'pos.void', null],
        'no_sale' => ['Open the drawer without a sale', 'pos.no_sale', null],
        'drawer' => ['Cash drop or pay-out', 'pos.drawer', null],
        'restaurant_void' => ['Void or refund a restaurant order', 'restaurant.void', null],
    ];

    public const STATUSES = ['pending' => 'Waiting', 'approved' => 'Approved', 'declined' => 'Declined', 'cancelled' => 'Cancelled at the till',
        'expired' => 'Not answered in time', 'used' => 'Approved and done'];

    /** Minutes the till waits for an answer, and an approval stays usable. */
    public const MINUTES = 10;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['details' => 'array', 'decided_at' => 'datetime', 'used_at' => 'datetime', 'expires_at' => 'datetime',
            'amount_minor' => 'integer', 'limit_amount' => 'integer'];
    }

    public function label(): string
    {
        return self::KINDS[$this->kind][0] ?? $this->kind;
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }
}
