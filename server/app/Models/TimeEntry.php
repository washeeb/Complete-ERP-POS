<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Time a person spent on a project on a day (or a timer still running), approved with their week's timesheet. */
class TimeEntry extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUSES = ['draft' => 'Not sent', 'submitted' => 'Waiting for approval', 'approved' => 'Approved', 'rejected' => 'Sent back'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'minutes' => 'integer', 'billable' => 'boolean', 'timer_started_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "2:30" */
    public static function hours(int $minutes): string
    {
        return intdiv($minutes, 60).':'.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT);
    }
}
