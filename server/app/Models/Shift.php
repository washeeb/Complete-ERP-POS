<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Shift extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'opening_float_minor' => 'integer', 'expected_cash_minor' => 'integer', 'counted_cash_minor' => 'integer', 'over_short_minor' => 'integer', 'counted_denoms' => 'array',
            'expected_other' => 'array', 'counted_other' => 'array', 'checked_count_minor' => 'integer', 'checked_denoms' => 'array', 'check_recounts' => 'integer',
            'checked_at' => 'datetime'];
    }

    /**
     * Mobile money and card at the close, one row per check: what the system expected, what was read from the merchant
     * phone / card terminal (null = not checked) and the difference. Only checks with takings or a figure entered.
     *
     * @return list<array{key: string, label: string, expected: int, counted: ?int, diff: ?int, pieces: bool}>
     */
    public function otherChecks(): array
    {
        $expected = $this->expected_other ?? [];
        $counted = $this->counted_other ?? [];
        $rows = [];
        foreach (SalePayment::CLOSE_CHECKS as $key => $label) {
            if (! array_key_exists($key, $expected) && ! array_key_exists($key, $counted)) {
                continue;
            }
            $e = (int) ($expected[$key] ?? 0);
            $c = array_key_exists($key, $counted) ? (int) $counted[$key] : null;
            $rows[] = ['key' => $key, 'label' => $label, 'expected' => $e, 'counted' => $c, 'diff' => $c === null ? null : $c - $e,
                'pieces' => in_array($key, SalePayment::COUNTED_IN_PIECES, true)];
        }

        return $rows;
    }

    public function drawerEvents(): HasMany
    {
        return $this->hasMany(TillDrawerEvent::class);
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The teller's own vault the shift was opened on (personal vaults). */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** The latest count correction, if a manager corrected the count after the close. */
    public function latestCorrection(): HasOne
    {
        return $this->hasOne(ShiftCountCorrection::class)->latest('corrected_at'); // newest first; eager loading keeps the first per shift
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
