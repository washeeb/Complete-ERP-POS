<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A shelf location in a bin-managed warehouse. Each such warehouse has one receiving bin (the dock). */
class Bin extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const KINDS = ['receiving' => 'Receiving dock', 'storage' => 'Storage'];

    protected $fillable = ['warehouse_id', 'code', 'name', 'kind', 'pick_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'pick_order' => 'integer'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
