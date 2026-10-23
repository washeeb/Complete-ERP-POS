<?php

namespace App\Domain\Pos;

use App\Domain\Devices\OfflineGrant;
use App\Domain\Distribution\RouteService;
use App\Domain\Hr\AttendanceService;
use App\Domain\Inventory\HandheldOps;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Sync\ReviewQueue;
use App\Models\Device;
use App\Models\ReviewItem;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Applies a till's or handheld's queued work (shift opens, sales, shift closes; receipts, moves, counts) in the order it happened.
 * Each event is its own transaction: one bad event does not block the rest (partial batch),
 * and resending a batch is harmless (every event is idempotent on its id).
 */
final class PosSyncService
{
    public const TYPES = ['shift_open', 'sale', 'shift_close', 'drawer', 'clock', ...HandheldOps::OPS];

    public function __construct(
        private readonly OfflineGrant $grants,
        private readonly ShiftService $shifts,
        private readonly SaleService $sales,
        private readonly ReviewQueue $reviews,
        private readonly AttendanceService $attendance,
    ) {}

    /** @return list<array{id: string, status: string, code?: string, detail?: string, receipt_no?: string}> */
    public function apply(Device $device, array $events): array
    {
        $results = [];
        foreach ($events as $e) {
            $id = is_array($e) ? (string) ($e['id'] ?? '') : '';
            try {
                if (! Str::isUuid($id) || ! in_array($e['type'] ?? null, self::TYPES, true) || ! is_array($e['payload'] ?? null)) {
                    throw new DomainException('Malformed queued event.', 'malformed');
                }
                $results[] = $r = ['id' => $id] + $this->one($device, $e);
                if ($r['status'] === 'accepted') {
                    // A previously refused item that now goes through (cause fixed, till retried).
                    DB::transaction(fn () => $this->reviews->closeBySystem('sync_event', $id, 'Accepted when the till retried'));
                }
            } catch (DomainException $ex) {
                $results[] = ['id' => $id, 'status' => 'rejected', 'code' => $ex->errorCode, 'detail' => $ex->getMessage()];
                $this->recordRejection($device, $id, $e['type'] ?? '?', $ex);
            }
        }

        return $results;
    }

    private function one(Device $device, array $e): array
    {
        $grant = $this->grants->verify($e['grant'] ?? null);
        if ($grant['device_id'] !== $device->id) {
            throw new DomainException('That offline sign-in belongs to another device.', 'invalid_grant');
        }
        $user = User::where('tenant_id', $device->tenant_id)->find($grant['user_id'])
            ?? throw new DomainException('The person on the offline sign-in no longer exists.', 'invalid_grant');
        $p = $e['payload'];

        if (in_array($e['type'], HandheldOps::OPS, true)) {
            // Warehouse handheld: received, moved, counted while the server could not be reached (or sent at once).
            return app(HandheldOps::class)->run($device, $user, $e['type'], $e['id'], $p);
        }

        switch ($e['type']) {
            case 'shift_open':
                if ($p['id'] !== $e['id']) {
                    throw new DomainException('Shift id mismatch.', 'malformed');
                }
                $existed = Shift::whereKey($p['id'])->exists();
                $shift = $this->shifts->open($device, $user, (int) $p['opening_float_minor'], $p['id'],
                    Carbon::parse($p['opened_at']), offline: true, vaultSessionId: $p['vault_session_id'] ?? null);
                if (! $existed) {
                    $this->flagGrant($device, $grant, $user, Carbon::parse($p['opened_at']), 'shift', $shift->id, 'Shift opened');
                }

                return ['status' => $existed ? 'duplicate' : 'accepted'];

            case 'sale':
                if ($p['id'] !== $e['id']) {
                    throw new DomainException('Sale id mismatch.', 'malformed');
                }
                $r = $this->sales->post($device, null, $p, $grant);

                return ['status' => $r['replayed'] ? 'duplicate' : 'accepted', 'receipt_no' => $r['sale']->receipt_no];

            case 'shift_close':
                $shift = Shift::where('device_id', $device->id)->find($p['shift_id'] ?? null)
                    ?? throw new DomainException('The shift being closed was not received.', 'no_shift');
                $wasClosed = $shift->status === 'closed';
                $this->shifts->close($device, $user, (int) $p['counted_cash_minor'], $p['note'] ?? null, $shift->id,
                    Carbon::parse($p['closed_at']), offline: true, denoms: is_array($p['denoms'] ?? null) ? $p['denoms'] : null,
                    other: is_array($p['other'] ?? null) ? $p['other'] : null);

                return ['status' => $wasClosed ? 'duplicate' : 'accepted'];

            case 'drawer': // pay-out, pay-in, cash drop, no sale or cash-limit warning recorded while offline
                $r = app(DrawerService::class)->record($device, $user, ['id' => $e['id']] + $p, offline: true);

                return ['status' => $r->wasRecentlyCreated ? 'accepted' : 'duplicate'];

            case 'visit': // Distribution: a rep's call on a customer, recorded while offline
                return app(RouteService::class)->visit($device, $user, ['id' => $e['id']] + $p);

            case 'order': // Distribution: an order taken on the road while offline
                $r = app(RouteService::class)->takeOrder($device, $user, ['id' => $e['id']] + $p);

                return ['status' => $r['status'], 'number' => $r['order']->number];

            case 'clock': // clocked in or out while offline; the PIN was checked against this person's offline sign-in on the till
                $r = $this->attendance->clock($device, $user, (string) ($p['kind'] ?? ''), $e['id'], Carbon::parse($p['occurred_at'] ?? 'now'), 'offline');

                return ['status' => $r['status']];
        }

        throw new DomainException('Unknown event.', 'malformed');
    }

    private function flagGrant(Device $device, array $grant, User $user, Carbon $at, string $subjectType, string $subjectId, string $what): void
    {
        if ($at->gt($this->grants->expiresAt($grant))) {
            DB::transaction(fn () => $this->reviews->add(Actor::user($user, 'pos', $device->id), 'grant_expired', $subjectType, $subjectId,
                "{$what} on {$device->name} after {$user->name}'s offline sign-in expired", [], $device->branch_id));
        }
    }

    /** The till keeps a refused event for review; the back office sees it too (once). */
    private function recordRejection(Device $device, string $eventId, string $type, DomainException $ex): void
    {
        if ($ex->errorCode === 'conflict' && $type === 'sale') {
            // fallthrough: a conflict is worth a human look as well
        }
        if (ReviewItem::where('subject_type', 'sync_event')->where('subject_id', Str::isUuid($eventId) ? $eventId : null)->exists()) {
            return;
        }
        $actor = Actor::system($device->tenant_id, "Device {$device->name}", 'sync');
        DB::transaction(fn () => $this->reviews->add($actor, 'sync_failed', 'sync_event', Str::isUuid($eventId) ? $eventId : null,
            "{$device->name}: queued {$type} refused — {$ex->getMessage()}", ['code' => $ex->errorCode, 'device_id' => $device->id], $device->branch_id));
    }
}
