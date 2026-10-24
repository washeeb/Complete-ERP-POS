<?php

namespace App\Models;

use App\Domain\Modules\EnabledModules;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchModule extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = ['branch_id', 'module_key', 'status', 'module_version', 'reason'];

    protected static function booted(): void
    {
        $forget = fn () => app(EnabledModules::class)->forget(); // the per-request list of switched-on modules
        static::saved($forget);
        static::deleted($forget);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
