<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A manager's correction of a closed shift's cash count (the shift itself is never edited). */
class ShiftCountCorrection extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['counted_before_minor' => 'integer', 'counted_after_minor' => 'integer', 'over_short_after_minor' => 'integer', 'corrected_at' => 'datetime'];
    }
}
