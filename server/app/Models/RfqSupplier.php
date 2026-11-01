<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One supplier asked on a request for quotation: their link, whether they answered, and their offer. */
class RfqSupplier extends Model
{
    use HasUuids;

    public const STATUSES = ['invited' => 'Asked', 'quoted' => 'Quoted', 'declined' => 'Declined'];

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'viewed_at' => 'datetime', 'quoted_at' => 'datetime', 'valid_until' => 'date',
            'delivery_days' => 'integer', 'payment_days' => 'integer', 'total_minor' => 'integer'];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function quoteLines(): HasMany
    {
        return $this->hasMany(RfqQuoteLine::class);
    }

    public function link(): string
    {
        return route('rfq.quote', $this->token);
    }
}
