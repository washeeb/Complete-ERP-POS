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
use Illuminate\Support\Carbon;
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

    /** Minutes within which a second shift opened by the same person on the same till is the same shift (a double press). */
    public const TWIN_MINUTES = 10;

    /** This till's shift by the id the till knows it by: its own id, or the id of a second open of it (shift_aliases). */
    public static function resolve(Device $device, ?string $id): ?Shift
    {
        if (! $id) {
            return null;
        }
        $shift = Shift::where('device_id', $device->id)->find($id);
        if ($shift) {
            return $shift;
        }
        $to = DB::table('shift_aliases')->where('id', $id)->where('device_id', $device->id)->value('shift_id');
        if ($to && ($shift = Shift::where('device_id', $device->id)->find($to))) {
            return $shift;
        }

        // A shift another till took over (this one was wiped or replaced): what this device still sends goes into it.
        return Shift::where('taken_over_from_device_id', $device->id)->find($to ?? $id);
    }

    /** Minutes without a word from a till before its open shift may be continued on another device of the same till. */
    public const LEFT_OPEN_SILENT_MINUTES = 15;

    /**
     * A shift left open on this device's till by another device that is gone: revoked, or silent for a while (wiped,
     * reinstalled, replaced). This device may continue it, with a manager's approval; null when it has its own shift.
     */
    public function leftOpenFor(Device $device): ?Shift
    {
        if (! $device->till_id || $this->current($device)) {
            return null;
        }
        $quiet = now()->subMinutes(self::LEFT_OPEN_SILENT_MINUTES);
        $gone = Device::where('till_id', $device->till_id)->whereKeyNot($device->id)
            ->where(fn ($q) => $q->where('status', '!=', 'active')->orWhere(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $quiet))
                ->where(fn ($q) => $q->whereNull('health_at')->orWhere('health_at', '<', $quiet))))
            ->pluck('id');

        return $gone->isEmpty() ? null : Shift::where('till_id', $device->till_id)->where('status', 'open')
            ->whereIn('device_id', $gone)->orderByDesc('opened_at')->first();
    }

    /**
     * Continue a shift left open on this till on this device (the till it was opened on was wiped or replaced). A manager
     * approves; the shift keeps its float and its sales, and closes here as usual. Sales the old device still sends land
     * in it.
     */
    public function takeOver(Device $device, User $cashier, string $shiftId, User $manager, string $reason): Shift
    {
        $shift = $this->leftOpenFor($device);
        if (! $shift || $shift->id !== $shiftId) {
            throw new DomainException('That shift can no longer be continued here: it was closed, or the till it was opened on is still in use.', 'not_left_open');
        }
        if (trim($reason) === '') {
            throw new DomainException('Give the reason (e.g. the till was reinstalled).');
        }
        $shift->forceFill(['device_id' => $device->id, 'taken_over_from_device_id' => $shift->device_id]);

        return $this->recorder->transaction(fn () => $this->recorder->save(Actor::user($cashier, 'pos', $device->id), $shift, 'shift.taken_over',
            mb_substr(trim($reason)." (approved by {$manager->name})", 0, 255), $shift->branch_id));
    }

    /** $id/$openedAt come from the till (offline shifts are opened without us); $offline skips the live access check (reviewed via the grant instead). */
    public function open(Device $device, User $user, int $floatMinor, ?string $id = null, ?CarbonInterface $openedAt = null, bool $offline = false, ?string $vaultSessionId = null, ?array $grant = null, ?string $vaultId = null): Shift
    {
        if ($id && ($existing = Shift::find($id))) {
            if ($existing->device_id !== $device->id) {
                throw new DomainException('A different shift with this id exists.', 'conflict');
            }

            return $existing; // replay
        }
        if ($id && ($twin = self::resolve($device, $id))) {
            return $twin; // a second open of a shift, already matched to it
        }
        $till = $this->till($device, $user, $offline);
        // Opened twice (owner report 2026-11-15): Open shift pressed again while the first press was on its way, so the
        // till queued a second shift seconds after the one the server has. Same person, same till, within 10 minutes: it is
        // the same shift. Its id is kept as another name for it, so the sales the till made under it are not refused.
        if ($id && $offline && $openedAt) {
            $twin = Shift::where('device_id', $device->id)->where('opened_by', $user->id)
                ->whereBetween('opened_at', [Carbon::instance($openedAt)->subMinutes(self::TWIN_MINUTES), Carbon::instance($openedAt)->addMinutes(self::TWIN_MINUTES)])
                ->orderByRaw('abs(extract(epoch from (opened_at - ?::timestamp)))', [Carbon::instance($openedAt)->utc()->format('Y-m-d H:i:s.u')])->first();
            if ($twin) {
                $this->recorder->transaction(function () use ($twin, $id, $device, $user, $openedAt) {
                    DB::table('shift_aliases')->insert(['id' => $id, 'tenant_id' => $twin->tenant_id, 'shift_id' => $twin->id, 'device_id' => $device->id,
                        'opened_at' => Carbon::instance($openedAt), 'created_at' => now(), 'updated_at' => now()]);
                    $this->recorder->audit->record(Actor::user($user, 'pos', $device->id), 'shift.opened_twice', $twin, [],
                        'The till opened this shift a second time (Open shift pressed again); both are one shift', $twin->branch_id);
                });

                return $twin;
            }
        }
        if ($floatMinor < 0) {
            throw new DomainException('The opening float cannot be negative.');
        }
        if ($this->current($device)) {
            throw new DomainException('This till already has an open shift.', 'shift_open');
        }
        // Automatic shifts: named by the time it opens; outside trading hours or again after closing, a manager allows it (online only).
        $auto = app(AutoShifts::class);
        $at = Carbon::instance($openedAt ?? now());
        $named = $offline
            ? ['shift_name' => $auto->enabled($till->branch_id, $till->tenant_id) ? $auto->nameAt($till->tenant_id, $at->copy()->setTimezone($till->branch?->timezone ?: 'UTC')) : null]
            : $auto->beforeOpen($device, $user, $till, $at, $grant);
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
        // Personal vaults: the shift opens on the teller's own vault (or one lent to her today), with its standard float.
        $own = app(TellerVaults::class)->forOpen($user, $till, $vaultId, $offline);
        if ($own && ! $offline) {
            $floatMinor = (int) $own->standard_float_minor;
        }
        $shift = new Shift;
        if ($id) {
            $shift->id = $id;
        }
        $shift->forceFill([
            'tenant_id' => $till->tenant_id, 'branch_id' => $till->branch_id, 'till_id' => $till->id, 'device_id' => $device->id,
            'opened_by' => $user->id, 'opened_by_name' => $user->name, 'currency_code' => $user->tenant->currency_code,
            'opening_float_minor' => $floatMinor, 'opened_at' => $openedAt ?? now(), 'status' => 'open', 'vault_id' => $own?->id,
        ] + $named);

        return $this->recorder->transaction(function () use ($actor, $shift, $till, $vault, $user, $offline) {
            $this->recorder->save($actor, $shift, $shift->granted_by_name ? 'shift.opened_granted' : 'shift.opened',
                $shift->granted_by_name ? "Allowed by {$shift->granted_by_name}: {$shift->grant_reason}" : null, $till->branch_id);
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
        $shift->shift_name = app(AutoShifts::class)->nameAtClose($shift, Carbon::instance($shift->closed_at));
        if ($shift->vault_id && app(TellerVaults::class)->flow($shift->tenant_id) !== 'manager') {
            $shift->forceFill(['checked_count_minor' => $countedMinor, 'checked_by' => $user->id, 'checked_by_name' => $user->name, 'checked_at' => $shift->closed_at]);
        }

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
     * close: expected cash, the count, over/short (posted to the books as usual). On a cash-control vault the manager's
     * count stands for the teller's, and the cashier then receives the vault as usual (blind count).
     */
    public function closeFromOffice(Actor $actor, Shift $shift, int $countedMinor, string $reason): Shift
    {
        $this->requireManager($actor, $shift);
        if (! $shift->isOpen()) {
            throw new DomainException('This shift is already closed.');
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
        if ($shift->vault_id) {
            $shift->forceFill(['checked_count_minor' => $countedMinor, 'checked_by' => $actor->id, 'checked_by_name' => $actor->name, 'checked_at' => now()]);
        }

        return $this->recorder->transaction(function () use ($actor, $shift, $reason, $countedMinor, $expected) {
            $this->recorder->save($actor, $shift, 'shift.closed_in_office', $reason, $shift->branch_id);
            if ($shift->vault_session_id) {
                $this->cash->countedInOffice($actor, $shift, $countedMinor, $expected);
            }

            return $shift;
        });
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
        if ($shift->vault_id && ! $shift->checked_at) {
            throw new DomainException('Count the vault first (Count the vault): that count is the shift\'s.');
        }
        if ($countedMinor < 0 || trim($reason) === '') {
            throw new DomainException('Give the right count (0 or more) and the reason.');
        }
        $before = $shift->latestCorrection?->counted_after_minor ?? (int) ($shift->checked_count_minor ?? $shift->counted_cash_minor);
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

    /**
     * The shift's sales by how they were paid, every method including those that never reach the drawer (mobile money
     * per network, card, vouchers, NHIS, on account…); the returns refunded, by how the money went back, so a refund the
     * customer never received shows at once as the reason the drawer is over; and the total (sales less refunds).
     * Not the float, pay-ins or customer-account payments: only what the shift sold.
     *
     * @return array{lines: list<array{label: string, amount_minor: int}>, refunds: list<array{label: string, amount_minor: int}>, total_minor: int}
     */
    public function takings(Shift $shift): array
    {
        $rows = SalePayment::query()->join('sales as s', 's.id', '=', 'sale_payments.sale_id')->where('s.shift_id', $shift->id)->where('s.status', 'posted')
            ->groupBy('s.kind', 'sale_payments.method', 'sale_payments.provider')
            ->selectRaw('s.kind, sale_payments.method, sale_payments.provider, SUM(sale_payments.amount_minor) AS amount')->toBase()->get();
        $sold = $refunded = [];
        foreach ($rows as $r) {
            $label = $r->method === 'momo' ? (SalePayment::MOMO_PROVIDERS[$r->provider ?: 'mtn'] ?? 'Mobile money') : (SalePayment::METHODS[$r->method] ?? $r->method);
            if ($r->kind === 'return') {
                $refunded[$label] = ($refunded[$label] ?? 0) - (int) $r->amount; // refunds are negative payments
            } else {
                $sold[$label] = ($sold[$label] ?? 0) + (int) $r->amount;
            }
        }
        $order = array_flip(array_values(array_unique(array_merge(['Cash'], array_values(SalePayment::MOMO_PROVIDERS), array_values(SalePayment::METHODS)))));
        $list = function (array $by) use ($order): array {
            $by = array_filter($by, fn ($v) => $v !== 0);
            uksort($by, fn ($a, $b) => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));

            return array_map(fn ($label, $amount) => ['label' => $label, 'amount_minor' => $amount], array_keys($by), $by);
        };

        return ['lines' => $list($sold), 'refunds' => $list($refunded), 'total_minor' => array_sum($sold) - array_sum($refunded)];
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
