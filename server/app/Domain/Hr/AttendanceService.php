<?php

namespace App\Domain\Hr;

use App\Domain\Devices\PosAuthService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Device;
use App\Models\User;
use App\Security\Authorizer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Clock in / out (owner decision 2026-10-14: on the till with the staff PIN; works offline with the person's offline
 * sign-in on that till). Events are append-only; a manager corrects with a new event or by voiding one, with a reason.
 * Events made on a branch server travel up to head office for payroll.
 */
final class AttendanceService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly PosAuthService $auth) {}

    /** Online clocking at a till: the PIN is checked here (with the usual lockout). */
    public function clockWithPin(Device $device, string $username, string $pin, string $kind, string $eventId, ?CarbonInterface $at = null): array
    {
        $user = $this->auth->verifyPin($device, $username, $pin);

        return $this->clock($device, $user, $kind, $eventId, $at ?? now(), 'till');
    }

    /** @return array{status: string, name: string, kind: string, at: string} */
    public function clock(Device $device, User $user, string $kind, string $eventId, CarbonInterface $at, string $source): array
    {
        if (! in_array($kind, ['in', 'out'], true)) {
            throw new DomainException('Clock in or clock out?', 'malformed');
        }
        if (Carbon::parse($at)->isAfter(now()->addMinutes(5))) {
            throw new DomainException('The till clock is ahead of the server. Fix the device time and try again.', 'clock_skew');
        }
        if (DB::table('attendance_events')->where('id', $eventId)->exists()) {
            return ['status' => 'duplicate', 'name' => $user->name, 'kind' => $kind, 'at' => Carbon::parse($at)->toIso8601String()];
        }
        $this->recorder->transaction(fn () => $this->insert($user, $device->branch_id, $device->id, $kind, $at, $source, null, null, $user->name, $eventId,
            Actor::user($user, 'pos', $device->id)));

        return ['status' => 'accepted', 'name' => $user->name, 'kind' => $kind, 'at' => Carbon::parse($at)->toIso8601String()];
    }

    /** A manager adds a missing clock-in or clock-out. */
    public function addManual(Actor $actor, User $user, Branch $branch, string $kind, string $at, string $reason): void
    {
        $this->requireManager($actor, $branch);
        if (! in_array($kind, ['in', 'out'], true) || trim($reason) === '') {
            throw new DomainException('Choose in or out and give a reason.');
        }
        if (Carbon::parse($at)->isFuture()) {
            throw new DomainException('A time in the future cannot be recorded.');
        }
        $this->recorder->transaction(fn () => $this->insert($user, $branch->id, null, $kind, Carbon::parse($at), 'manual', null, $reason, $actor->name, null, $actor));
    }

    /** A manager cancels a wrong event (the event stays; a voiding event is added). */
    public function void(Actor $actor, string $eventId, string $reason): void
    {
        $e = DB::table('attendance_events')->where('id', $eventId)->first() ?? throw new DomainException('That clock event does not exist.');
        $this->requireManager($actor, Branch::findOrFail($e->branch_id));
        if (trim($reason) === '') {
            throw new DomainException('Give a reason.');
        }
        if (DB::table('attendance_events')->where('voids_id', $eventId)->exists() || $e->voids_id) {
            throw new DomainException('Already corrected.');
        }
        $this->recorder->transaction(fn () => $this->insert(User::findOrFail($e->user_id), $e->branch_id, null, $e->kind, Carbon::parse($e->occurred_at), 'manual',
            $eventId, $reason, $actor->name, null, $actor));
    }

    /** Insert events received from another node (idempotent). */
    public function apply(array $rows): void
    {
        foreach ($rows as $r) {
            DB::table('attendance_events')->insertOrIgnore($r);
        }
    }

    /**
     * Hours per day for a person: clock-ins paired with the next clock-out; a clock-in without a clock-out counts nothing
     * (and is listed so a manager can fix it). Overtime is time beyond the standard day.
     *
     * @return array{days: array<string, array{centi: int, overtime_centi: int}>, total_centi: int, overtime_centi: int, open: list<string>}
     */
    public static function timesheet(string $userId, string $from, string $to, int $dayHours = 8, string $tz = 'UTC'): array
    {
        $rows = DB::table('attendance_events')->where('user_id', $userId)
            ->whereBetween('occurred_at', [Carbon::parse($from, $tz)->startOfDay()->utc(), Carbon::parse($to, $tz)->endOfDay()->utc()])
            ->orderBy('occurred_at')->get();
        $voided = $rows->whereNotNull('voids_id')->pluck('voids_id')->flip();
        $days = [];
        $open = [];
        $in = null;
        foreach ($rows as $r) {
            if ($r->voids_id || isset($voided[$r->id])) {
                continue;
            }
            $at = Carbon::parse($r->occurred_at, 'UTC');
            if ($r->kind === 'in') {
                if ($in) {
                    $open[] = $in->copy()->setTimezone($tz)->format('Y-m-d H:i');
                }
                $in = $at;
            } elseif ($in) {
                $day = $in->copy()->setTimezone($tz)->toDateString();
                $days[$day]['centi'] = ($days[$day]['centi'] ?? 0) + intdiv((int) $in->diffInSeconds($at) * 100, 3600);
                $in = null;
            }
        }
        if ($in) {
            $open[] = $in->copy()->setTimezone($tz)->format('Y-m-d H:i');
        }
        $total = 0;
        $ot = 0;
        foreach ($days as $d => $v) {
            $days[$d]['overtime_centi'] = max(0, $v['centi'] - $dayHours * 100);
            $total += $v['centi'];
            $ot += $days[$d]['overtime_centi'];
        }
        ksort($days);

        return ['days' => $days, 'total_centi' => $total, 'overtime_centi' => $ot, 'open' => $open];
    }

    private function insert(User $user, string $branchId, ?string $deviceId, string $kind, CarbonInterface $at, string $source, ?string $voids, ?string $reason,
        string $by, ?string $id, Actor $actor): void
    {
        $row = ['id' => $id ?? (string) Str::uuid7(), 'tenant_id' => $user->tenant_id, 'user_id' => $user->id, 'branch_id' => $branchId, 'device_id' => $deviceId,
            'kind' => $kind, 'occurred_at' => Carbon::parse($at)->utc()->format('Y-m-d H:i:s.u'), 'source' => $source, 'voids_id' => $voids,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null, 'recorded_by_name' => $by, 'origin_node' => (string) config('erp.node.id'),
            'received_at' => now()->format('Y-m-d H:i:s.u')];
        DB::table('attendance_events')->insert($row);
        $this->recorder->audit->recordRef($actor, $voids ? 'attendance.voided' : "attendance.clock_{$kind}", 'user', $user->id,
            ['at' => [null, $row['occurred_at']], 'source' => [null, $source]], $reason, $branchId);
        $this->recorder->outbox->publishRaw($actor, 'attendance_batch', $row['id'], 1, "attendance.{$kind}", ['events' => [$row]]);
    }

    private function requireManager(Actor $actor, Branch $branch): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'attendance.manage', $branch)) {
            throw new AccessDenied;
        }
    }
}
