<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A posted, balanced journal entry. Append-only: corrected by a reversing entry, never edited. */
class JournalEntry extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const UPDATED_AT = null;

    public const KINDS = ['auto' => 'Automatic', 'manual' => 'Journal', 'opening' => 'Opening balances', 'reversal' => 'Reversal'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['entry_date' => 'date', 'total_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'period_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }
}
