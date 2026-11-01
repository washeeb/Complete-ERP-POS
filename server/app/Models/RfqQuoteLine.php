<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RfqQuoteLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty' => 'decimal:3', 'unit_cost_minor' => 'integer', 'awarded' => 'boolean'];
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(RfqSupplier::class, 'rfq_supplier_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(RfqLine::class, 'rfq_line_id');
    }
}
