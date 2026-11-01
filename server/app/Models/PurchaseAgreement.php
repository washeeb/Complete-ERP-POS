<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A contract (agreed prices for a period) or blanket order (agreed prices with quantities or a value to draw down). */
class PurchaseAgreement extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const KINDS = ['contract' => 'Contract (agreed prices)', 'blanket' => 'Blanket order (agreed prices and quantities)'];

    public const STATUSES = ['draft' => 'Draft', 'active' => 'In force', 'closed' => 'Closed'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'approved_at' => 'datetime', 'value_cap_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseAgreementLine::class)->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** In force today: approved, not closed, and today between its dates. */
    public function inForce(): bool
    {
        return $this->status === 'active' && ! today()->isBefore($this->starts_on) && ! today()->isAfter($this->ends_on);
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->status === 'active' && today()->isAfter($this->ends_on) => 'Ended',
            $this->status === 'active' && today()->isBefore($this->starts_on) => 'Starts '.$this->starts_on->format('j M Y'),
            default => self::STATUSES[$this->status] ?? $this->status,
        };
    }
}
