<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An order to make a quantity of a product from its bill of materials, carrying its actual cost. */
class WorkOrder extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['draft' => 'Draft', 'released' => 'Released', 'in_progress' => 'In production', 'done' => 'Finished', 'cancelled' => 'Cancelled'];

    public const OPEN = ['draft', 'released', 'in_progress'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty_planned' => 'decimal:3', 'qty_done' => 'decimal:3', 'qty_scrapped' => 'decimal:3', 'qty_awaiting_qc' => 'decimal:3',
            'qc_required' => 'boolean', 'operations' => 'array', 'due_on' => 'date', 'released_at' => 'datetime', 'started_at' => 'datetime', 'closed_at' => 'datetime',
            'material_minor' => 'integer', 'labour_minor' => 'integer', 'by_product_minor' => 'integer', 'output_minor' => 'integer', 'variance_minor' => 'integer'];
    }

    public function materials(): HasMany
    {
        return $this->hasMany(WorkOrderMaterial::class)->orderBy('line_no');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function bom(): BelongsTo
    {
        return $this->belongsTo(Bom::class);
    }

    /** Cost still in work in progress: materials and labour charged, less by-products and output already in stock. */
    public function wipMinor(): int
    {
        return $this->material_minor + $this->labour_minor - $this->by_product_minor - $this->output_minor - $this->variance_minor;
    }
}
