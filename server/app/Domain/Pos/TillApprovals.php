<?php

namespace App\Domain\Pos;

use App\Domain\Messaging\Notifier;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Till;
use App\Models\TillApproval;
use App\Models\User;
use App\Security\Authorizer;
use App\Security\Reconfirm;
use Illuminate\Database\Eloquent\Model;

/**
 * A till asks a manager who is not at the till (owner request 2026-11-15). The cashier presses *Ask a manager*; everyone
 * who could approve it by PIN (same permission and limit, never the cashier) gets it on the bell and on their phone, opens
 * it, sees what is asked and approves after confirming it is them (passkey, fingerprint, authenticator). The till sees the
 * answer within seconds and goes on; the approval is used once, by that till, for that kind and up to that amount. A
 * manager at the till can still approve there, by scanning their card or typing their username, and their PIN.
 */
final class TillApprovals
{
    public function __construct(private readonly Recorder $recorder, private readonly Notifier $notifier, private readonly Authorizer $authz) {}

    public function ask(Device $device, User $cashier, string $kind, int $amountMinor, int $limitAmount, string $summary, ?array $details, ?string $reason): TillApproval
    {
        if (! isset(TillApproval::KINDS[$kind])) {
            throw new DomainException('Unknown kind of approval.');
        }
        [$label, $permission, $limitKey] = TillApproval::KINDS[$kind];
        $r = new TillApproval(['branch_id' => $device->branch_id, 'till_id' => $device->till_id, 'device_id' => $device->id,
            'cashier_id' => $cashier->id, 'cashier_name' => $cashier->name, 'kind' => $kind, 'permission' => $permission, 'limit_key' => $limitKey,
            'amount_minor' => max(0, $amountMinor), 'limit_amount' => max(0, $limitAmount), 'summary' => mb_substr($summary, 0, 1000),
            'details' => $details ? array_slice(array_values($details), 0, 50) : null, 'reason' => $reason ? mb_substr($reason, 0, 255) : null,
            'status' => 'pending', 'expires_at' => now()->addMinutes(TillApproval::MINUTES)]);
        $r->tenant_id = $device->tenant_id;
        $this->recorder->save(self::tillActor($device, $cashier), $r, 'pos.approval_asked', $r->summary, $device->branch_id);

        $where = ($device->till_id ? Till::withoutGlobalScopes()->find($device->till_id)?->name : null) ?? $device->name;
        $this->notifier->toPermission($device->tenant_id, $permission, $device->branch_id, "{$where}: {$label}",
            "{$cashier->name} asks: {$r->summary}".($r->reason ? " ({$r->reason})" : '').'. Answer within '.TillApproval::MINUTES.' minutes.',
            url('/office/till-requests?id='.$r->id), [$cashier->id], topic: 'approvals');

        return $r;
    }

    /** Could this person say yes: the same rule as approving by PIN at the till. */
    public function mayDecide(User $user, TillApproval $r): bool
    {
        if ($user->id === $r->cashier_id || $user->tenant_id !== $r->tenant_id || ! $user->is_active) {
            return false;
        }
        $target = $this->target($r);

        return $this->authz->allows($user, $r->permission, $target)
            && (! $r->limit_key || $this->authz->allowsAmount($user, $r->permission, $target, $r->limit_key, $r->limit_amount));
    }

    /** A manager answers from the back office or their phone. Yes needs them to have confirmed it is them in the last minutes. */
    public function decide(Actor $actor, TillApproval $r, bool $yes, ?string $note = null): TillApproval
    {
        if ($yes && $actor->user && $this->mayDecide($actor->user, $r)) {
            Reconfirm::require($actor, null, 'approve a till request', always: true);
        }

        return $this->recorder->transaction(function () use ($actor, $r, $yes, $note) {
            $r = TillApproval::lockForUpdate()->findOrFail($r->id);
            $this->expire($r);
            if ($r->status !== 'pending') {
                throw new DomainException('This request was already answered, cancelled or ran out of time ('.(TillApproval::STATUSES[$r->status] ?? $r->status).').', 'approval_closed');
            }
            if (! $actor->user || ! $this->mayDecide($actor->user, $r)) {
                throw new AccessDenied('You cannot approve this (permission or limit), or it is your own request.');
            }
            if (! $yes && blank($note)) {
                throw new DomainException('Say why it is declined: the cashier sees it.');
            }
            $r->fill(['status' => $yes ? 'approved' : 'declined', 'decided_by' => $actor->id, 'decided_by_name' => $actor->name, 'decided_via' => 'remote',
                'decided_at' => now(), 'decision_note' => $note ? mb_substr($note, 0, 255) : null]);
            $this->recorder->save($actor, $r, $yes ? 'pos.approval_given' : 'pos.approval_declined', $note ?: $r->summary, $r->branch_id);

            return $r;
        });
    }

    /** A manager standing at the till approves there: card or username, and PIN (same lockout as signing in). */
    public function approveHere(Device $device, TillApproval $r, array $approval): TillApproval
    {
        $this->ownedBy($device, $r);
        $approver = app(SupervisorApproval::class)->verify($device, ['username' => (string) ($approval['username'] ?? ''), 'pin' => (string) ($approval['pin'] ?? '')],
            $r->permission, $this->target($r), $r->limit_key, $r->limit_key ? $r->limit_amount : 0, $r->cashier_id);

        return $this->recorder->transaction(function () use ($device, $r, $approver) {
            $r = TillApproval::lockForUpdate()->findOrFail($r->id);
            $this->expire($r);
            if ($r->status !== 'pending') {
                throw new DomainException('This request was already answered, cancelled or ran out of time.', 'approval_closed');
            }
            $r->fill(['status' => 'approved', 'decided_by' => $approver->id, 'decided_by_name' => $approver->name, 'decided_via' => 'till', 'decided_at' => now()]);
            $this->recorder->save(self::tillActor($device, $approver), $r, 'pos.approval_given', $r->summary, $r->branch_id);

            return $r;
        });
    }

    public function cancel(Device $device, TillApproval $r): TillApproval
    {
        $this->ownedBy($device, $r);
        if ($r->status === 'pending') {
            $r->status = 'cancelled';
            $this->recorder->save(self::tillActor($device, null), $r, 'pos.approval_cancelled', null, $r->branch_id);
        }

        return $r;
    }

    /** What the till sees while it waits. */
    public function status(Device $device, TillApproval $r): TillApproval
    {
        $this->ownedBy($device, $r);
        $this->expire($r);

        return $r;
    }

    /**
     * The till goes on with an approval it was given: once, from this till, for the same kind of thing, for the same
     * cashier, up to the amount asked, within the minutes after the yes, and only while the approver may still approve it.
     */
    public function consume(Device $device, string $id, string $permission, ?string $limitKey, int $amount, string $cashierId): User
    {
        $fail = fn (string $why) => new DomainException($why, 'approval_failed');

        return $this->recorder->transaction(function () use ($device, $id, $permission, $limitKey, $amount, $cashierId, $fail) {
            $r = TillApproval::withoutGlobalScopes()->where('tenant_id', $device->tenant_id)->lockForUpdate()->find($id);
            if (! $r || $r->device_id !== $device->id) {
                throw $fail('That approval is not for this till.');
            }
            if ($r->status === 'used') {
                throw $fail('That approval was already used. Ask again.');
            }
            if ($r->status !== 'approved' || $r->decided_at->copy()->addMinutes(TillApproval::MINUTES)->isPast()) {
                throw $fail('There is no approval for this (it was declined, cancelled or ran out of time). Ask again.');
            }
            if ($r->permission !== $permission || ($cashierId !== '' && $r->cashier_id !== $cashierId)) {
                throw $fail('That approval was for something else. Ask again.');
            }
            $within = $limitKey ? $amount <= $r->limit_amount : $amount <= max($r->amount_minor, $r->limit_amount);
            if ($amount > 0 && ! $within) {
                throw $fail('This is more than the manager approved. Ask again for the new amount.');
            }
            $approver = User::withoutGlobalScopes()->find($r->decided_by);
            if (! $approver || ! $this->mayDecide($approver, $r)) {
                throw $fail('The manager who approved can no longer approve this. Ask again.');
            }
            $r->fill(['status' => 'used', 'used_at' => now()]);
            $this->recorder->save(self::tillActor($device, null), $r, 'pos.approval_used', null, $r->branch_id);

            return $approver;
        });
    }

    private function expire(TillApproval $r): void
    {
        if ($r->status === 'pending' && $r->expires_at->isPast()) {
            $r->status = 'expired';
            $r->save();
        }
    }

    private function ownedBy(Device $device, TillApproval $r): void
    {
        if ($r->device_id !== $device->id || $r->tenant_id !== $device->tenant_id) {
            throw new AccessDenied('That request is not from this till.');
        }
    }

    private function target(TillApproval $r): Model
    {
        return ($r->till_id ? Till::withoutGlobalScopes()->find($r->till_id) : null) ?? Branch::withoutGlobalScopes()->findOrFail($r->branch_id);
    }

    private static function tillActor(Device $device, ?User $user): Actor
    {
        return $user ? Actor::user($user, 'pos', $device->id) : new Actor(null, "Device {$device->name}", 'pos', $device->tenant_id, deviceId: $device->id);
    }
}
