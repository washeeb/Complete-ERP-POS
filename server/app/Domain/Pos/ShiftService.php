<?php

namespace App\Domain\Pos;

use App\Domain\Finance\GeneralLedger;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Device;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\ShiftCountCorrection;
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
     * $other: what the merchant phone shows per MoMo network and the card terminal's total ({"momo:mtn": minor, "card": minor});
     * a check left out is not checked. Differences are shown and listed for a manager, not posted: a MoMo payment can
     * arrive late, so the mobile money reconciliation decides.
     */
    public function close(Device $device, User $user, int $countedMinor, ?string $note = null, ?string $shiftId = null, ?CarbonInterface $closedAt = null, bool $offline = false, ?array $denoms = null, ?array $other = null): Shift
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
        $other = $this->otherCounts($other);
        $expected = $this->expectedCash($shift);
        $shift->forceFill([
            'closed_by' => $user->id, 'closed_by_name' => $user->name, 'closed_at' => $closedAt ?? now(), 'status' => 'closed',
            'expected_cash_minor' => $expected, 'counted_cash_minor' => $countedMinor, 'counted_denoms' => $denoms,
            'expected_other' => $this->expectedOther($shift) ?: null, 'counted_other' => $other ?: null,
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

    /**
     * Close a shift from the back office: for a shift no till can close any more (the till was reinstalled or replaced,
     * or the person left without closing). A manager enters the cash actually there and why. Same result as a till
     * close: expected cash, the count, over/short (posted to the books as usual).
     */
    public function closeFromOffice(Actor $actor, Shift $shift, int $countedMinor, string $reason): Shift
    {
        $this->requireManager($actor, $shift);
        if (! $shift->isOpen()) {
            throw new DomainException('This shift is already closed.');
        }
        if ($shift->vault_session_id) {
            throw new DomainException('This shift is on a cash-control vault: close it at Cash → Shifts and vaults.');
        }
        if ($countedMinor < 0 || trim($reason) === '') {
            throw new DomainException('Give the cash counted (0 or more) and the reason.');
        }
        $expected = $this->expectedCash($shift);
        $shift->forceFill([
            'closed_by' => $actor->id, 'closed_by_name' => $actor->name, 'closed_at' => now(), 'status' => 'closed',
            'expected_other' => $this->expectedOther($shift) ?: null,
            'expected_cash_minor' => $expected, 'counted_cash_minor' => $countedMinor, 'over_short_minor' => $countedMinor - $expected,
            'close_note' => mb_substr('Closed in the back office: '.trim($reason), 0, 255),
        ]);

        return $this->recorder->transaction(fn () => $this->recorder->save($actor, $shift, 'shift.closed_in_office', $reason, $shift->branch_id));
    }

    /**
     * Correct a closed shift's count (a typing mistake, cash found later). The shift keeps its original count; the
     * correction records the right amount, who and why, and posts the difference between cash and cash over/short.
     */
    public function correctCount(Actor $actor, Shift $shift, int $countedMinor, string $reason): ShiftCountCorrection
    {
        $this->requireManager($actor, $shift);
        if ($shift->isOpen()) {
            throw new DomainException('Close the shift first.');
        }
        if ($shift->vault_session_id) {
            throw new DomainException('This shift is on a cash-control vault: its count is decided at Cash → Shifts and vaults.');
        }
        if ($countedMinor < 0 || trim($reason) === '') {
            throw new DomainException('Give the right count (0 or more) and the reason.');
        }
        $before = $shift->latestCorrection?->counted_after_minor ?? (int) $shift->counted_cash_minor;
        if ($countedMinor === $before) {
            throw new DomainException('That is the count already recorded.');
        }
        $expected = (int) $shift->expected_cash_minor;
        $row = new ShiftCountCorrection;
        $row->forceFill(['tenant_id' => $shift->tenant_id, 'shift_id' => $shift->id, 'branch_id' => $shift->branch_id, 'counted_before_minor' => $before,
            'counted_after_minor' => $countedMinor, 'over_short_after_minor' => $countedMinor - $expected, 'reason' => mb_substr(trim($reason), 0, 255),
            'corrected_by' => $actor->id, 'corrected_by_name' => $actor->name, 'corrected_at' => now()]);
        $delta = $countedMinor - $before;

        return $this->recorder->transaction(function () use ($actor, $row, $shift, $delta, $reason) {
            $this->recorder->save($actor, $row, 'shift.count_corrected', $reason, $shift->branch_id);
            app(GeneralLedger::class)->post($actor, now()->toDateString(), 'auto', 'Shift count corrected: '.mb_substr(trim($reason), 0, 120), [
                ['account' => 'cash_till', 'amount' => $delta, 'branch_id' => $shift->branch_id],
                ['account' => 'cash_over_short', 'amount' => -$delta, 'branch_id' => $shift->branch_id],
            ], [['shift_correction', $row->id]], 'shift_corrections');

            return $row;
        });
    }

    private function requireManager(Actor $actor, Shift $shift): void
    {
        if (! $actor->user || ! $this->authz->allows($actor->user, 'cash.setup', $shift->branch)) {
            throw new AccessDenied('Closing or correcting a shift needs a manager (cash set-up permission at this branch).');
        }
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

        // Pay-ins add; expenses paid out and cash drops to the safe take away.
        $cash += DrawerService::cashEffect($shift);

        return $shift->opening_float_minor + $cash;
    }

    /**
     * Mobile money per network and card taken through this till in the shift: sales and returns (refunds are negative
     * payments), customer-account payments and booking deposits (taken in, refunded out); gift vouchers and credit notes
     * taken as payment; and the number of coupons used. Only non-zero checks.
     *
     * @return array<string, int> {"momo:mtn": minor, "card": minor}
     */
    public function expectedOther(Shift $shift): array
    {
        $key = fn (object $r) => match ($r->method) {
            'momo' => 'momo:'.($r->provider ?: 'mtn'),
            'gift_card', 'store_credit' => 'vouchers',
            default => 'card',
        };
        $rows = SalePayment::whereIn('sale_id', $shift->sales()->where('status', 'posted')->select('id'))->whereIn('method', ['momo', 'card', 'gift_card', 'store_credit'])
            ->groupBy('method', 'provider')->selectRaw('method, provider, SUM(amount_minor) AS amount')->toBase()->get()
            ->concat(DB::table('customer_payments')->where('shift_id', $shift->id)->whereIn('method', ['momo', 'card'])
                ->groupBy('method', 'provider')->selectRaw('method, provider, SUM(amount_minor) AS amount')->get())
            ->concat(DB::table('deposit_movements')->where('shift_id', $shift->id)->whereIn('method', ['momo', 'card'])
                ->groupBy('method', 'provider')->selectRaw("method, provider, SUM(CASE WHEN kind = 'taken' THEN amount_minor ELSE -amount_minor END) AS amount")->get());
        $out = [];
        foreach ($rows as $r) {
            $k = $key($r);
            $out[$k] = ($out[$k] ?? 0) + (int) $r->amount;
        }
        // Coupons: how many sales used a coupon code (each paper coupon is kept with the tray).
        $out['coupons'] = $shift->sales()->where('status', 'posted')->where('kind', 'sale')->whereNotNull('coupon_code')->count();

        return array_filter($out, fn ($v) => $v !== 0);
    }

    /** @return array<string, int> only known checks, 0 or more */
    private function otherCounts(?array $other): array
    {
        $out = [];
        foreach ($other ?? [] as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (! array_key_exists($k, SalePayment::CLOSE_CHECKS) || ! is_numeric($v) || (int) $v < 0) {
                throw new DomainException('Mobile money and card: enter the amount shown (0 or more) or leave it empty.');
            }
            $out[$k] = (int) $v;
        }

        return $out;
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
