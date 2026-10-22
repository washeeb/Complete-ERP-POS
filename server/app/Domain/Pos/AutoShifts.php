<?php

namespace App\Domain\Pos;

use App\Domain\Support\DomainException;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Automatic shifts (owner request 2026-10-22), per branch (Settings → Tills and cash): a till session is named after the
 * shift whose hours it opens in (Cash → Cash set-up: Morning 07:00–15:00, Afternoon 14:00–23:00 by default); one that
 * lasts the straight-day length or more is a Straight day. A till opens only within the trading hours, and only once
 * per shift: outside the hours, or again after its shift was closed, a manager allows it with their PIN and a reason.
 * Closing and counting are always allowed. Shifts opened offline are named but not blocked (the server cannot ask).
 */
final class AutoShifts
{
    public const STRAIGHT_DAY = 'Straight day';

    /** Consecutive shifts may overlap by at most this (owner: handover hour). */
    public const MAX_OVERLAP_MINUTES = 60;

    public function __construct(private readonly Settings $settings, private readonly SupervisorApproval $approval) {}

    public function enabled(Branch|string|null $branch, string $tenantId): bool
    {
        return (bool) $this->settings->forBranch('pos.auto_shifts', $branch, $tenantId);
    }

    /** The shift a time falls in: the latest-starting shift that has begun and not ended (so 14:30 is Afternoon, not Morning). */
    public function nameAt(string $tenantId, Carbon $local): ?string
    {
        $t = $local->format('H:i:s');
        $hit = ShiftTemplate::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('is_active', true)->orderByDesc('starts_at')->get()
            ->first(fn ($s) => self::within($t, (string) $s->starts_at, (string) $s->ends_at));

        return $hit?->name;
    }

    /** The name kept when the shift closes: Straight day when it ran long enough, else the shift it opened in. */
    public function nameAtClose(Shift $shift, Carbon $closedAt): ?string
    {
        if (! $this->enabled($shift->branch_id, $shift->tenant_id) && ! $shift->shift_name) {
            return null;
        }
        $hours = (int) $this->settings->get('pos.straight_day_hours', $shift->tenant_id);

        return $shift->opened_at->diffInMinutes($closedAt, true) >= $hours * 60 ? self::STRAIGHT_DAY : $shift->shift_name;
    }

    /**
     * Before a till opens (online): the shift name, and who allowed it when it needed allowing.
     *
     * @param  array{username?: string, pin?: string, reason?: string}|null  $grant
     * @return array{shift_name: ?string, granted_by_name: ?string, grant_reason: ?string}
     */
    public function beforeOpen(Device $device, User $user, Till $till, Carbon $at, ?array $grant): array
    {
        $out = ['shift_name' => null, 'granted_by_name' => null, 'grant_reason' => null];
        if (! $this->enabled($till->branch_id, $till->tenant_id)) {
            return $out;
        }
        $local = $at->copy()->setTimezone($this->timezone($till));
        $out['shift_name'] = $this->nameAt($till->tenant_id, $local);
        $why = null;
        [$from, $until] = $this->hours($till);
        if ($from && ! self::within($local->format('H:i:s'), $from, $until)) {
            $why = ['outside_hours', "Tills open from {$from} to {$until}. A manager can allow it with their PIN and a reason."];
        } elseif ($out['shift_name'] && Shift::where('till_id', $till->id)->where('status', 'closed')->where('shift_name', $out['shift_name'])
            ->where('opened_at', '>=', $local->copy()->startOfDay()->utc())->exists()) {
            $why = ['till_closed', "This till's {$out['shift_name']} shift was already closed today. A manager can allow it to open again with their PIN and a reason."];
        }
        if (! $why) {
            return $out;
        }
        if (! $grant || blank($grant['username'] ?? null)) {
            throw new DomainException($why[1], $why[0]);
        }
        $reason = trim((string) ($grant['reason'] ?? ''));
        if (mb_strlen($reason) < 3) {
            throw new DomainException('The manager must give the reason.', $why[0]);
        }
        $manager = $this->approval->verify($device, $grant, 'pos.shift.grant', $till, null, 0, $user->id);

        return array_merge($out, ['granted_by_name' => $manager->name, 'grant_reason' => mb_substr($reason, 0, 255)]);
    }

    /** Shifts may overlap by at most an hour (a shift that runs past midnight is not allowed). */
    public static function assertTemplate(string $startsAt, string $endsAt, array $others): void
    {
        $min = fn (string $t) => (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
        if ($min($endsAt) <= $min($startsAt)) {
            throw new DomainException('A shift ends after it starts (on the same day).');
        }
        foreach ($others as [$name, $s, $e]) {
            $overlap = min($min($endsAt), $min($e)) - max($min($startsAt), $min($s));
            if ($overlap > self::MAX_OVERLAP_MINUTES) {
                throw new DomainException("It overlaps {$name} by ".intdiv($overlap, 60).' h '.($overlap % 60).' min; shifts may overlap by at most 1 hour (the handover).');
            }
        }
    }

    /** @return array{0: ?string, 1: ?string} "07:00" and "23:00", or nulls when not set */
    public function hours(Till $till): array
    {
        $v = (string) $this->settings->forBranch('pos.trading_hours', $till->branch_id, $till->tenant_id);

        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)-([01]\d|2[0-4]):([0-5]\d)$/', $v, $m) ? ["{$m[1]}:{$m[2]}", "{$m[3]}:{$m[4]}"] : [null, null];
    }

    private function timezone(Till $till): string
    {
        return Branch::withoutGlobalScopes()->find($till->branch_id)?->timezone ?: (Tenant::find($till->tenant_id)?->timezone ?: 'UTC');
    }

    private static function within(string $t, string $from, string $until): bool
    {
        return substr($t, 0, 5) >= substr($from, 0, 5) && substr($t, 0, 5) < substr($until, 0, 5);
    }
}
