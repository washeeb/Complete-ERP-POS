<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchModule extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['branch_id', 'module_key', 'status', 'module_version', 'reason'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
