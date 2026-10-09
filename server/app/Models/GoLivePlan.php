<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** How a company moves onto the system: data sources, cut-over style, pilot style, checklist progress (Phase 14). */
class GoLivePlan extends Model
{
    use BelongsToTenant, HasUuids;

    public const SOURCES = ['excel' => 'Excel / CSV sheets', 'quickbooks' => 'QuickBooks', 'other_pos' => 'Another till (POS) system', 'fresh' => 'Starting fresh'];

    public const CUTOVER = [
        'parallel' => 'Parallel run, then switch (recommended)',
        'big_bang' => 'Switch fully on one day',
        'one_till' => 'One till or branch first, then the rest',
    ];

    public const PILOT = [
        'one_shop' => 'One shop, with support on call (recommended)',
        'several' => 'Several shops at once',
        'dry_run' => 'Internal dry run first (practice company)',
    ];

    public const STATUSES = ['planning' => 'Preparing', 'parallel' => 'Parallel run', 'live' => 'Live', 'rolled_back' => 'Rolled back'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sources' => 'array', 'steps' => 'array', 'old_totals' => 'array', 'pilot_branch_ids' => 'array', 'go_live_on' => 'date',
            'parallel_until' => 'date', 'live_at' => 'datetime', 'pilot_report_sent_on' => 'date'];
    }
}
