<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cash tray with its standard opening float: of one till for one shift (e.g. "T01 · Morning", the cashier chain), or a
 * teller's own vault (holder set, no shift; of the branch or of one till).
 */
class Vault extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['name', 'standard_float_minor', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'standard_float_minor' => 'integer'];
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function shiftTemplate(): BelongsTo
    {
        return $this->belongsTo(ShiftTemplate::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_id');
    }
}
