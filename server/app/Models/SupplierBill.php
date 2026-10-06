<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier's invoice, matched to the goods received (3-way match) or charged to expense accounts. */
class SupplierBill extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['pending_approval' => 'Waiting for approval', 'posted' => 'Posted', 'rejected' => 'Rejected'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['bill_date' => 'date', 'due_on' => 'date', 'posted_at' => 'datetime', 'tax_breakdown' => 'array',
            'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer', 'variance_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierBillLine::class)->orderBy('line_no');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
