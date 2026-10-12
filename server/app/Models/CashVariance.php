<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A difference found at a count, owned by the person responsible, until the income controller decides. */
class CashVariance extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STAGES = ['teller' => 'Till vault (teller)', 'cashier' => 'Handover (cashier)'];

    public const TREATMENTS = ['over_short' => 'Cash over / short (the business bears it)', 'charge_staff' => 'Charged to the staff member'];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'decided_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(User::class, 'person_id');
    }

    public function vaultSession(): BelongsTo
    {
        return $this->belongsTo(VaultSession::class);
    }

    public function handover(): BelongsTo
    {
        return $this->belongsTo(CashHandover::class, 'handover_id');
    }
}
