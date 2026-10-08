<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bill of materials: what one batch of a product uses and gives off, and the operations (routing) that make it. */
class Bom extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const STATUSES = ['draft' => 'Draft', 'active' => 'In use', 'archived' => 'Replaced'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['output_qty' => 'decimal:3', 'revision' => 'integer', 'qc_required' => 'boolean', 'operations' => 'array'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BomLine::class)->orderBy('line_no');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
