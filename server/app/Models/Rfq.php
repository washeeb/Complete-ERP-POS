<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A request for quotation: the same list sent to several suppliers, their sealed prices compared and awarded. */
class Rfq extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['open' => 'Waiting for quotes', 'closed' => 'Closed: compare and award', 'awarded' => 'Awarded', 'cancelled' => 'Cancelled'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['closes_at' => 'datetime', 'needed_by' => 'date', 'closed_at' => 'datetime', 'awarded_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(RfqLine::class)->orderBy('line_no');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(RfqSupplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    /** Suppliers can still send or change prices. */
    public function acceptsQuotes(): bool
    {
        return $this->status === 'open' && $this->closes_at->isFuture();
    }

    /** The prices may be seen: closed by the buyer, or the closing time has passed (sealed until then). */
    public function isUnsealed(): bool
    {
        return in_array($this->status, ['closed', 'awarded'], true) || ($this->status === 'open' && ! $this->closes_at->isFuture());
    }
}
