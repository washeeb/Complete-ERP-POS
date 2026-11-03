<?php

namespace App\Domain\Projects;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\TimeEntry;
use App\Models\User;
use App\Security\Authorizer;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Staff time on projects (paid add-on "projects"). Each person logs hours on a project and task for a day — typed, or
 * with a start / stop timer — then sends their week. A manager with the approval right (not for their own time)
 * approves or sends it back with a reason; approved hours become project costs (and billable time).
 */
final class Timesheets
{
    public const MAX_MINUTES_A_DAY = 24 * 60;

    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly Projects $projects, private readonly Notifier $notifier) {}

    /** Add or change one of the person's own entries (not sent yet, or sent back). */
    public function log(Actor $actor, TimeEntry $e, array $d): TimeEntry
    {
        $project = Project::find($d['project_id'] ?? $e->project_id) ?? throw new DomainException('Choose the project.');
        $this->projects->require($actor, 'projects.time', $project->branch);
        if (! $project->isOpen()) {
            throw new DomainException("Project {$project->number} is closed.");
        }
        if ($e->exists && ($e->user_id !== $actor->id || ! in_array($e->status, ['draft', 'rejected'], true))) {
            throw new DomainException('Only your own time that is not sent yet (or was sent back) can be changed.');
        }
        $task = ($d['task_id'] ?? null) ? ProjectTask::where('project_id', $project->id)->find($d['task_id']) : null;
        if (($d['task_id'] ?? null) && ! $task) {
            throw new DomainException('Choose a task of this project.');
        }
        $minutes = isset($d['hours']) ? (int) round(((float) $d['hours']) * 60) : (int) ($d['minutes'] ?? $e->minutes ?? 0);
        $date = CarbonImmutable::parse($d['work_date'] ?? $e->work_date ?? now())->startOfDay();
        if ($minutes <= 0 || $minutes > self::MAX_MINUTES_A_DAY) {
            throw new DomainException('Enter the hours worked (more than 0, at most 24).');
        }
        if ($date->isAfter(now()->endOfDay())) {
            throw new DomainException('Time cannot be logged for a day still to come.');
        }
        $day = (int) TimeEntry::where('user_id', $actor->id)->whereDate('work_date', $date)->when($e->exists, fn ($q) => $q->whereKeyNot($e->id))->sum('minutes');
        if ($day + $minutes > self::MAX_MINUTES_A_DAY) {
            throw new DomainException('That makes more than 24 hours on '.$date->format('j M').'.');
        }

        return $this->recorder->transaction(function () use ($actor, $e, $d, $project, $task, $minutes, $date) {
            $isNew = ! $e->exists;
            $e->forceFill(['tenant_id' => $actor->tenantId, 'project_id' => $project->id, 'task_id' => $task?->id, 'user_id' => $actor->id,
                'work_date' => $date->toDateString(), 'minutes' => $minutes, 'note' => isset($d['note']) ? (mb_substr(trim((string) $d['note']), 0, 255) ?: null) : $e->note,
                'billable' => $project->isBilled() && ($task ? $task->billable : true) && (bool) ($d['billable'] ?? true), 'status' => 'draft', 'reject_reason' => null]);

            return $this->recorder->save($actor, $e, $isNew ? 'time.logged' : 'time.changed', branchId: $project->branch_id);
        });
    }

    public function delete(Actor $actor, TimeEntry $e): void
    {
        if ($e->user_id !== $actor->id || ! in_array($e->status, ['draft', 'rejected'], true)) {
            throw new DomainException('Only your own time that is not sent yet can be removed.');
        }
        $this->recorder->transaction(function () use ($actor, $e) {
            $this->recorder->audit->record($actor, 'time.removed', $e, ['minutes' => [$e->minutes, null]]);
            $e->delete();
        });
    }

    /** Start a timer on a project (and task); a timer already running for the person stops first. */
    public function start(Actor $actor, Project $project, ?ProjectTask $task = null, ?string $note = null): TimeEntry
    {
        $this->projects->require($actor, 'projects.time', $project->branch);
        if (! $project->isOpen()) {
            throw new DomainException("Project {$project->number} is closed.");
        }

        return $this->recorder->transaction(function () use ($actor, $project, $task, $note) {
            $this->stop($actor);
            $e = (new TimeEntry)->forceFill(['tenant_id' => $actor->tenantId, 'project_id' => $project->id, 'task_id' => $task?->id, 'user_id' => $actor->id,
                'work_date' => now()->toDateString(), 'minutes' => 0, 'timer_started_at' => now(), 'note' => $note ? mb_substr(trim($note), 0, 255) : null,
                'billable' => $project->isBilled() && ($task?->billable ?? true), 'status' => 'draft']);

            return $this->recorder->save($actor, $e, 'time.timer_started', branchId: $project->branch_id);
        });
    }

    /** Stop the person's running timer: the time since it started is added, to the nearest minute (at least one). */
    public function stop(Actor $actor): ?TimeEntry
    {
        $e = TimeEntry::where('user_id', $actor->id)->whereNotNull('timer_started_at')->first();
        if (! $e) {
            return null;
        }
        $minutes = max(1, (int) round($e->timer_started_at->diffInSeconds(now()) / 60));

        return $this->recorder->transaction(function () use ($actor, $e, $minutes) {
            $e->forceFill(['minutes' => min(self::MAX_MINUTES_A_DAY, $e->minutes + $minutes), 'timer_started_at' => null]);

            return $this->recorder->save($actor, $e, 'time.timer_stopped', TimeEntry::hours($minutes));
        });
    }

    /** Send the person's week (Monday to Sunday of $day) for approval; a running timer stops first. */
    public function submitWeek(Actor $actor, string $day): int
    {
        [$from, $to] = self::week($day);
        $this->stop($actor);
        $entries = TimeEntry::where('user_id', $actor->id)->whereBetween('work_date', [$from, $to])->whereIn('status', ['draft', 'rejected'])->where('minutes', '>', 0)->get();
        if ($entries->isEmpty()) {
            throw new DomainException('No time to send for this week.');
        }

        return $this->recorder->transaction(function () use ($actor, $entries, $from) {
            TimeEntry::whereIn('id', $entries->pluck('id'))->update(['status' => 'submitted', 'reject_reason' => null]);
            $this->recorder->audit->record($actor, 'time.week_submitted', 'timesheet', ['entries' => [null, $entries->count()], 'minutes' => [null, $entries->sum('minutes')]],
                'week of '.$from);
            foreach ($entries->pluck('project')->unique('branch_id') as $project) {
                $this->notifier->toPermission($actor->tenantId, 'projects.approve', $project->branch_id, "{$actor->name}'s time for the week of ".CarbonImmutable::parse($from)->format('j M'),
                    TimeEntry::hours((int) $entries->sum('minutes')).' hour(s) to approve.', url('/admin/time-approvals'), [$actor->id], 'approvals');
            }

            return $entries->count();
        });
    }

    /** @param iterable<TimeEntry> $entries */
    public function approve(Actor $actor, iterable $entries): int
    {
        return $this->decide($actor, collect($entries), 'approved', null);
    }

    /** @param iterable<TimeEntry> $entries */
    public function reject(Actor $actor, iterable $entries, string $reason): int
    {
        if (trim($reason) === '') {
            throw new DomainException('Say what to correct.');
        }

        return $this->decide($actor, collect($entries), 'rejected', mb_substr(trim($reason), 0, 255));
    }

    private function decide(Actor $actor, Collection $entries, string $status, ?string $reason): int
    {
        $entries = $entries->filter(fn (TimeEntry $e) => $e->status === 'submitted');
        foreach ($entries as $e) {
            if (! $actor->user || ! $this->authz->allows($actor->user, 'projects.approve', $e->project->branch)) {
                throw new AccessDenied;
            }
            if ($e->user_id === $actor->id) {
                throw new DomainException('Your own time is approved by someone else.', 'own_document');
            }
        }
        $this->projects->assertLicensed();

        return $this->recorder->transaction(function () use ($actor, $entries, $status, $reason) {
            foreach ($entries as $e) {
                $e->forceFill(['status' => $status, 'reject_reason' => $reason, 'decided_by_name' => $actor->name, 'decided_at' => now()]);
                $this->recorder->save($actor, $e, 'time.'.$status, $reason, $e->project->branch_id);
                if ($status === 'approved') {
                    $this->projects->collectTime($e);
                }
            }
            foreach ($entries->groupBy('user_id') as $userId => $mine) {
                Notification::make()->title($status === 'approved' ? 'Your time was approved' : 'Your time was sent back: '.$reason)
                    ->body(TimeEntry::hours((int) $mine->sum('minutes')).' hour(s)')->sendToDatabase(User::find($userId));
            }

            return $entries->count();
        });
    }

    /** @return array{0: string, 1: string} Monday and Sunday of the week $day is in */
    public static function week(string $day): array
    {
        $d = CarbonImmutable::parse($day);

        return [$d->startOfWeek(CarbonImmutable::MONDAY)->toDateString(), $d->endOfWeek(CarbonImmutable::SUNDAY)->toDateString()];
    }
}
