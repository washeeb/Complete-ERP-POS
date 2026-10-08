<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasOriginNode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A report built in the report builder, optionally sent on a schedule. */
class SavedReport extends Model
{
    use BelongsToTenant, HasOriginNode, HasUuids;

    public const SCHEDULES = ['none' => 'Not scheduled', 'daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['definition' => 'array', 'recipients' => 'array', 'shared' => 'boolean', 'next_run_at' => 'datetime', 'last_run_at' => 'datetime'];
    }
}
