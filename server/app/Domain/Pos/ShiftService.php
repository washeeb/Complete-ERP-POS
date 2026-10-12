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
use App\Models\VaultSession;
use App\Security\Authorizer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * A till session: opening float → sales → blind cash count → over/short.
 * With cash control on (CashControl), a shift is opened on the vault the cashier issued to this teller: its float is
 * the vault's, and the teller's count at close goes to the cashier, who counts again. Drops, pay-ins/outs and X/Z
 * reports build on this in later POS work.
 */
final class ShiftService
{
    public function __construct(private readonly Recorder $recorder, private readonly Authorizer $authz, private readonly CashControl $cash) {}

    public function current(Device $device): ?Shift
    {
        return Shift::where('device_id', $device->id)->where('status', 'open')->first();
    }

    /** $id/$openedAt come from the till (offline shifts are opened without us); $offline skips the live access check (reviewed via the grant instead). */
    public function open(Device $device, User $user, int $floatMinor, ?string $id = null, ?CarbonInterface $openedAt = null, bool $offline = false, ?string $vaultSessionId = null): Shift
    {
        if ($id && ($existing = Shift::find($id))) {
            if ($existing->device_id !== $device->id) {
                throw new DomainException('A different shift with this id exists.', 'conflict');
            }

            return $existing; // replay
        }
        $till = $this->till($device, $user, $offline);
        if ($floatMinor < 0) {
            throw new DomainException('The opening float cannot be negative.');
        }
        if ($this->current($device)) {
            throw new DomainException('This till already has an open shift.', 'shift_open');
        }
        $actor = Actor::user($user, 'pos', $device->id);
        $vault = null;
        if ($this->cash->enabled($till->tenant_id)) {
            $vault = $vaultSessionId ? VaultSession::find($vaultSessionId) : null;
            if (! $vault && ! $offline) {
                throw new DomainException('Ask the cashier for your vault: with cash control on, a till opens on the vault issued to you.', 'no_vault');
            }
            if ($vault && $vault->status === 'issued') {
                $floatMinor = $vault->float_minor; // the float is what the cashier issued, not what the till typed
            }
        }
        $shift = new Shift;
        if ($id) {
            $shift->id = $id;
        }
        $shift->forceFill([
            'tenant_id' => $till->tenant_id, 'branch_id' => $till->branch_id, 'till_id' => $till->id, 'device_id' => $device->id,
            'opened_by' => $user->id, 'opened_by_name' => $user->name, 'currency_code' => $user->tenant->currency_code,
            'opening_float_minor' => $floatMinor, 'opened_at' => $openedAt ?? now(), 'status' => 'open',
        ]);

        return $this->recorder->transaction(function () use ($actor, $shift, $till, $vault, $user, $offline) {
            $this->recorder->save($actor, $shift, 'shift.opened', branchId: $till->branch_id);
            if ($vault && ($vault->status === 'issued' || ! $offline)) {
                $this->cash->attachShift($vault, $shift, $user, $offline);
                $shift->forceFill(['vault_session_id' => $vault->id]);
                $this->recorder->save($actor, $shift, 'shift.vault_linked', branchId: $till->branch_id);
            }

            return $shift;
        });
    }

    /**
     * Blind close: the person on the till counts first; the expected amount is revealed only in the result (and not at
     * all on a vault: the cashier counts it next). $denoms: the count note by note ({key: count}), when given.
     */
    public function close(Device $device, User $user, int $countedMinor, ?string $note = null, ?string $shiftId = null, ?CarbonInterface $closedAt = null, bool $offline = false, ?array $denoms = null): Shift
    {
        $this->till($device, $user, $offline);
        if ($shiftId && ($named = Shift::where('device_id', $device->id)->find($shiftId)) && $named->status === 'closed') {
            return $named; // replay
        }
        $shift = $this->current($device) ?? throw new DomainException('There is no open shift on this till.', 'no_shift');
        if ($shiftId && $shift->id !== $shiftId) {
            throw new DomainException('That is not the open shift on this till.', 'no_shift');
        }
        if ($denoms !== null && CashControl::denominations($shift->currency_code)) {
            [$countedMinor, $denoms] = CashControl::total($shift->currency_code, $denoms, null);
        }
        if ($countedMinor < 0) {
            throw new DomainException('Counted cash cannot be negative.');
        }
        $expected = $this->expectedCash($shift);
        $shift->forceFill([
            'closed_by' => $user->id, 'closed_by_name' => $user->name, 'closed_at' => $closedAt ?? now(), 'status' => 'closed',
            'expected_cash_minor' => $expected, 'counted_cash_minor' => $countedMinor, 'counted_denoms' => $denoms,
            'over_short_minor' => $countedMinor - $expected, 'close_note' => $note,
        ]);

        return $this->recorder->transaction(function () use ($device, $user, $shift, $note, $countedMinor, $denoms, $expected) {
            $this->recorder->save(Actor::user($user, 'pos', $device->id), $shift, 'shift.closed', $note, $shift->branch_id);
            if ($shift->vault_session_id) {
                $this->cash->tellerCounted($shift, $user, $countedMinor, $denoms, $expected);
            }

            return $shift;
        });
    }

    public function expectedCash(Shift $shift): int
    {
        $cash = (int) SalePayment::whereIn('sale_id', $shift->sales()->where('status', 'posted')->select('id'))
            ->where('method', 'cash')->sum('amount_minor');

        // Money taken at the till against customer accounts is in the drawer too.
        $cash += (int) DB::table('customer_payments')->where('shift_id', $shift->id)->where('method', 'cash')->sum('amount_minor');

        // Restaurant booking deposits taken (in) and refunded (out) in cash at this till.
        $cash += (int) DB::table('deposit_movements')->where('shift_id', $shift->id)->where('method', 'cash')
            ->selectRaw("COALESCE(SUM(CASE WHEN kind = 'taken' THEN amount_minor ELSE -amount_minor END), 0) AS c")->value('c');

        return $shift->opening_float_minor + $cash;
    }

    private function till(Device $device, User $user, bool $offline = false): Till
    {
        $till = $device->till_id ? Till::find($device->till_id) : null;
        if (! $till) {
            throw new DomainException('This device is not assigned to a till. Assign it under System → Devices.', 'no_till');
        }
        if (! $offline && ! $this->authz->allows($user, 'pos.access', $till)) {
            throw new AccessDenied('You are not set up to use this till.');
        }

        return $till;
    }
}
