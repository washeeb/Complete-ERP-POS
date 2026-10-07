<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Two ingredients that should not be taken together (local table; a data provider can feed it later). */
class DrugInteraction extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const SEVERITIES = ['minor' => 'Minor', 'moderate' => 'Moderate', 'major' => 'Major — do not dispense together without the prescriber'];

    protected $guarded = [];
}
