<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The NHIS parts of a branch's sales, claimed from the NHIA together (Pharmacy → NHIS claims). */
class NhisClaim extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['open' => 'Being prepared', 'submitted' => 'Sent to the NHIA', 'settled' => 'Paid', 'withdrawn' => 'Withdrawn'];

    public const METHODS = ['bank' => 'Bank transfer', 'cheque' => 'Cheque', 'momo' => 'Mobile money', 'cash' => 'Cash'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_to' => 'date', 'paid_on' => 'date', 'submitted_at' => 'datetime', 'claimed_minor' => 'integer',
            'paid_minor' => 'integer', 'rejected_minor' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(NhisClaimLine::class);
    }
}
