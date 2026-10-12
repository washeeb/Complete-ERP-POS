<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One working day of one vault: float issued → teller's shift → teller's blind count → cashier's blind count → handed over. */
class VaultSession extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['issued' => 'Float issued', 'open' => 'Shift open', 'counted' => 'Counted by the teller',
        'received' => 'Received by the cashier', 'handed_over' => 'Handed to the income controller', 'cancelled' => 'Cancelled'];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'issued_at' => 'datetime', 'counted_at' => 'datetime', 'received_at' => 'datetime',
            'float_minor' => 'integer', 'expected_minor' => 'integer', 'teller_count_minor' => 'integer', 'cashier_count_minor' => 'integer',
            'teller_denoms' => 'array', 'cashier_denoms' => 'array', 'cashier_recounts' => 'integer'];
    }

    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function handover(): BelongsTo
    {
        return $this->belongsTo(CashHandover::class, 'handover_id');
    }
}
