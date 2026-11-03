<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Costs moved from a project's work in progress to project costs, when it is invoiced or closed (posted to the books). */
class ProjectBilling extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['released_minor' => 'integer', 'released_on' => 'date'];
    }
}
