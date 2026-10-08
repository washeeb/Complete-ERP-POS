<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A rep's monthly sales target (before tax), and optionally a number of visits. */
class RepTarget extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['target_minor' => 'integer', 'visit_target' => 'integer', 'year' => 'integer', 'month' => 'integer'];
    }
}
