<?php

namespace App\Domain\Pos;

use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Device;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\Till;
use App\Models\User;
use App\Security\Authorizer;

/**
 * A till session: opening float → sales → blind cash count → over/short.
 * Drops, pay-ins/outs and X/Z reports build on this in later POS work.
 */
final class ShiftService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz) {}

    public function current(Device $device): ?Shift
    {
        return $device->till_id ? Shift::where('till_id', $device->till_id)->where('status', 'open')->first() : null;
    }

    public function open(Device $device, User $user, int $floatMinor): Shift
    {
        $till = $this->till($device, $user);
        if ($floatMinor < 0) {
            throw new DomainException('The opening float cannot be negative.');
        }
        if ($this->current($device)) {
            throw new DomainException('This till already has an open shift.', 'shift_open');
        }
        $actor = Actor::user($user, 'pos', $device->id);
        $shift = new Shift;
        $shift->forceFill([
            'tenant_id' => $till->tenant_id, 'branch_id' => $till->branch_id, 'till_id' => $till->id, 'device_id' => $device->id,
            'opened_by' => $user->id, 'opened_by_name' => $user->name, 'currency_code' => $user->tenant->currency_code,
            'opening_float_minor' => $floatMinor, 'opened_at' => now(), 'status' => 'open',
        ]);

        return $this->recorder->save($actor, $shift, 'shift.opened', branchId: $till->branch_id);
    }

    /** Blind close: the cashier counts first; the expected amount is revealed only in the result. */
    public function close(Device $device, User $user, int $countedMinor, ?string $note = null): Shift
    {
        $this->till($device, $user);
        $shift = $this->current($device) ?? throw new DomainException('There is no open shift on this till.', 'no_shift');
        if ($countedMinor < 0) {
            throw new DomainException('Counted cash cannot be negative.');
        }
        $expected = $this->expectedCash($shift);
        $shift->forceFill([
            'closed_by' => $user->id, 'closed_by_name' => $user->name, 'closed_at' => now(), 'status' => 'closed',
            'expected_cash_minor' => $expected, 'counted_cash_minor' => $countedMinor,
            'over_short_minor' => $countedMinor - $expected, 'close_note' => $note,
        ]);

        return $this->recorder->save(Actor::user($user, 'pos', $device->id), $shift, 'shift.closed', $note, $shift->branch_id);
    }

    public function expectedCash(Shift $shift): int
    {
        $cash = (int) SalePayment::whereIn('sale_id', $shift->sales()->where('status', 'posted')->select('id'))
            ->where('method', 'cash')->sum('amount_minor');

        return $shift->opening_float_minor + $cash;
    }

    private function till(Device $device, User $user): Till
    {
        $till = $device->till_id ? Till::find($device->till_id) : null;
        if (! $till) {
            throw new DomainException('This device is not assigned to a till. Assign it under System → Devices.', 'no_till');
        }
        if (! $this->authz->allows($user, 'pos.access', $till)) {
            throw new AccessDenied('You are not set up to use this till.');
        }

        return $till;
    }
}
