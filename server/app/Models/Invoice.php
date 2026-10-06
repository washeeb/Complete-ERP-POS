<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Customer invoice. Immutable once posted; settled by payments and credit notes (allocations). */
class Invoice extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['posted' => 'Posted', 'credited' => 'Credited'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_on' => 'date', 'due_on' => 'date', 'prices_include_tax' => 'boolean', 'tax_breakdown' => 'array', 'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('line_no');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
