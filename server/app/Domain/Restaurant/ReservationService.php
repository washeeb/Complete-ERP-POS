<?php

namespace App\Domain\Restaurant;

use App\Domain\Modules\ModuleService;
use App\Domain\Pos\ShiftService;
use App\Domain\Pos\SupervisorApproval;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DepositMovement;
use App\Models\Device;
use App\Models\DiningOrder;
use App\Models\DiningTable;
use App\Models\Reservation;
use App\Models\SalePayment;
use App\Models\User;
use App\Security\Authorizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Table bookings (owner decision 2026-10-18): name, phone, party size, time and table; an optional deposit taken at the
 * till and held as a customer deposit until it is used on the bill, refunded, or forfeited after a no-show; an SMS
 * confirmation and reminder through the SMS gateway hook.
 */
final class ReservationService
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly ModuleService $modules,
        private readonly ShiftService $shifts,
        private readonly SupervisorApproval $approvals,
        private readonly DiningService $dining,
    ) {}

    /** @param array{branch_id?: string, guest_name?: string, phone?: ?string, party_size?: int, starts_at?: string, duration_minutes?: int, dining_table_id?: ?string, customer_id?: ?string, note?: ?string} $data */
    public function save(Actor $actor, Reservation $r, array $data): Reservation
    {
        $branch = Branch::findOrFail($data['branch_id'] ?? $r->branch_id);
        $this->require($actor, $branch, 'restaurant.serve');
        if ($r->exists && $r->status !== 'booked') {
            throw new DomainException('Only a booking that has not started can be changed.');
        }
        $name = trim((string) ($data['guest_name'] ?? $r->guest_name));
        $phone = array_key_exists('phone', $data) ? (preg_replace('/[^0-9+]/', '', (string) $data['phone']) ?: null) : $r->phone;
        $party = (int) ($data['party_size'] ?? $r->party_size ?? 0);
        $starts = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $r->starts_at;
        $duration = (int) ($data['duration_minutes'] ?? $r->duration_minutes ?? 90);
        if ($name === '' || mb_strlen($name) > 120 || $party < 1 || $party > 200 || ! $starts || $duration < 15 || $duration > 600) {
            throw new DomainException('Enter the guest’s name, the number of guests and the date and time.');
        }
        if ($phone !== null && (strlen($phone) < 9 || strlen($phone) > 20)) {
            throw new DomainException('Check the phone number.');
        }
        if (! $r->exists && $starts->lt(now()->subMinutes(5))) {
            throw new DomainException('The booking time has already passed.');
        }
        $tableId = array_key_exists('dining_table_id', $data) ? ($data['dining_table_id'] ?: null) : $r->dining_table_id;
        if ($tableId) {
            $table = DiningTable::where('branch_id', $branch->id)->where('is_active', true)->find($tableId) ?? throw new DomainException('Choose a table at this branch.');
            if ($party > $table->seats + 2) {
                throw new DomainException("Table {$table->code} seats {$table->seats}.");
            }
            $end = $starts->copy()->addMinutes($duration);
            $clash = Reservation::where('dining_table_id', $table->id)->where('status', 'booked')->when($r->exists, fn ($q) => $q->whereKeyNot($r->id))
                ->where('starts_at', '<', $end)->get()->first(fn ($o) => $o->starts_at->copy()->addMinutes($o->duration_minutes)->gt($starts));
            if ($clash) {
                throw new DomainException("Table {$table->code} is booked for {$clash->guest_name} at {$clash->starts_at->setTimezone($this->tz($branch))->format('H:i')}.", 'table_booked');
            }
        }
        $customer = array_key_exists('customer_id', $data) ? ($data['customer_id'] ? Customer::find($data['customer_id']) ?? throw new DomainException('That customer is not known here.') : null) : null;
        $isNew = ! $r->exists;

        return $this->recorder->transaction(function () use ($actor, $r, $branch, $name, $phone, $party, $starts, $duration, $tableId, $customer, $data, $isNew) {
            $r->forceFill(['branch_id' => $branch->id, 'guest_name' => $name, 'phone' => $phone, 'party_size' => $party, 'starts_at' => $starts,
                'duration_minutes' => $duration, 'dining_table_id' => $tableId, 'note' => array_key_exists('note', $data) ? (mb_substr(trim((string) $data['note']), 0, 255) ?: null) : $r->note]
                + ($customer ? ['customer_id' => $customer->id] : []));
            if ($isNew) {
                $r->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'reservation'), 'status' => 'booked',
                    'created_by_name' => $actor->name]);
            }
            if (! $isNew && $r->isDirty('starts_at')) {
                $r->reminder_sent_at = null; // a new time gets a new reminder
            }

            return $this->recorder->save($actor, $r, $isNew ? 'reservation.booked' : 'reservation.changed', branchId: $branch->id);
        });
    }

    /** A deposit paid at the till (counts in the shift's cash) and held until used on the bill, refunded or forfeited. */
    public function takeDeposit(Device $device, User $user, Reservation $r, string $method, int $amount, ?string $provider = null, ?string $reference = null): Reservation
    {
        $branch = $this->tillBranch($device, $user, $r);
        if (! in_array($r->status, ['booked'], true)) {
            throw new DomainException('A deposit can only be taken for a booking that has not started.');
        }
        $this->checkMoney($method, $amount, $provider, $reference);
        $shift = $this->shifts->current($device) ?? throw new DomainException('Open a shift on this till first.', 'no_shift');
        $actor = Actor::user($user, 'pos', $device->id);

        return $this->recorder->transaction(function () use ($actor, $r, $branch, $shift, $method, $amount, $provider, $reference) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            $this->movement($actor, $r, $branch, 'taken', $amount, $method, $provider, $reference, $shift->id);
            $r->forceFill(['deposit_minor' => $r->deposit_minor + $amount, 'deposit_status' => 'held']);

            return $this->recorder->save($actor, $r, 'reservation.deposit_taken', branchId: $branch->id, payload: ['amount_minor' => $amount]);
        });
    }

    /** Give back what is left of the deposit (cancelled booking, or goodwill after a no-show). Needs a supervisor. */
    public function refundDeposit(Device $device, User $user, Reservation $r, string $method, ?array $approval, ?string $provider = null, ?string $reference = null): Reservation
    {
        $branch = $this->tillBranch($device, $user, $r);
        $by = $this->supervisor($device, $user, $branch, $approval);
        $left = $r->depositLeft();
        if ($left <= 0) {
            throw new DomainException('There is no deposit left to refund.');
        }
        $this->checkMoney($method, $left, $provider, $reference);
        $shift = $this->shifts->current($device) ?? throw new DomainException('Open a shift on this till first.', 'no_shift');
        $actor = Actor::user($user, 'pos', $device->id);

        return $this->recorder->transaction(function () use ($actor, $r, $branch, $shift, $method, $left, $provider, $reference, $by) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            $this->movement($actor, $r, $branch, 'refunded', $left, $method, $provider, $reference, $shift->id);
            $r->forceFill(['deposit_status' => 'refunded']);

            return $this->recorder->save($actor, $r, 'reservation.deposit_refunded', "Approved by {$by}", $branch->id, ['amount_minor' => $left]);
        });
    }

    /** No-show (or late cancellation): the deposit left becomes the restaurant's income. Managers only. */
    public function forfeitDeposit(Actor $actor, Reservation $r, string $reason): Reservation
    {
        $branch = Branch::findOrFail($r->branch_id);
        $this->require($actor, $branch, 'restaurant.manage');
        if (! in_array($r->status, ['no_show', 'cancelled'], true)) {
            throw new DomainException('Only the deposit of a no-show or a cancelled booking can be kept.');
        }
        $left = $r->depositLeft();
        if ($left <= 0 || trim($reason) === '') {
            throw new DomainException('There is no deposit to keep, or the reason is missing.');
        }

        return $this->recorder->transaction(function () use ($actor, $r, $branch, $left, $reason) {
            $r = Reservation::lockForUpdate()->findOrFail($r->id);
            $this->movement($actor, $r, $branch, 'forfeited', $left, null, null, null, null);
            $r->forceFill(['deposit_status' => 'forfeited']);

            return $this->recorder->save($actor, $r, 'reservation.deposit_forfeited', trim($reason), $branch->id, ['amount_minor' => $left]);
        });
    }

    /** The guests arrived: open their bill on the booked (or a chosen) table. */
    public function seat(Device $device, User $user, Reservation $r, ?string $tableId = null): DiningOrder
    {
        $branch = $this->tillBranch($device, $user, $r);
        if ($r->status !== 'booked') {
            throw new DomainException('This booking is not waiting to be seated.');
        }
        $order = $this->dining->open($device, $user, ['id' => (string) Str::uuid7(), 'kind' => 'table', 'dining_table_id' => $tableId ?: $r->dining_table_id,
            'covers' => $r->party_size, 'customer_id' => $r->customer_id, 'note' => "Booking {$r->number} ({$r->guest_name})"]);
        $actor = Actor::user($user, 'pos', $device->id);
        $this->recorder->transaction(function () use ($actor, $r, $order, $branch) {
            $order->forceFill(['reservation_id' => $r->id])->save();
            $r->forceFill(['status' => 'seated', 'dining_order_id' => $order->id, 'dining_table_id' => $order->dining_table_id]);
            $this->recorder->save($actor, $r, 'reservation.seated', branchId: $branch->id);
        });

        return $order->fresh(['items', 'table']);
    }

    public function noShow(Actor $actor, Reservation $r): Reservation
    {
        return $this->close($actor, $r, 'no_show', 'reservation.no_show');
    }

    public function cancel(Actor $actor, Reservation $r, string $reason): Reservation
    {
        return $this->close($actor, $r, 'cancelled', 'reservation.cancelled', $reason);
    }

    /** Bookings at a branch for the floor view: today's (from an hour ago), soonest first. */
    public static function upcoming(string $branchId)
    {
        return Reservation::where('branch_id', $branchId)->where('status', 'booked')->where('starts_at', '>=', now()->subHours(2))
            ->where('starts_at', '<', now()->addDays(1)->endOfDay())->orderBy('starts_at')->get();
    }

    private function close(Actor $actor, Reservation $r, string $status, string $action, ?string $reason = null): Reservation
    {
        $branch = Branch::findOrFail($r->branch_id);
        $this->require($actor, $branch, 'restaurant.serve');
        if ($r->status !== 'booked') {
            throw new DomainException('This booking is no longer open.');
        }
        $r->forceFill(['status' => $status]);

        return $this->recorder->save($actor, $r, $action, $reason, $branch->id);
    }

    private function movement(Actor $actor, Reservation $r, Branch $branch, string $kind, int $amount, ?string $method, ?string $provider, ?string $reference, ?string $shiftId): void
    {
        $m = (new DepositMovement)->forceFill(['tenant_id' => $r->tenant_id, 'reservation_id' => $r->id, 'branch_id' => $branch->id, 'shift_id' => $shiftId,
            'kind' => $kind, 'method' => $method, 'provider' => $provider, 'reference' => $reference ? mb_substr($reference, 0, 80) : null, 'amount_minor' => $amount,
            'by_name' => $actor->name, 'occurred_at' => now()]);
        $m->save();
        $this->recorder->outbox->publish($actor, $m, "deposit.{$kind}");
    }

    private function checkMoney(string $method, int $amount, ?string $provider, ?string $reference): void
    {
        if (! in_array($method, ['cash', 'momo', 'card'], true) || $amount <= 0) {
            throw new DomainException('Choose cash, mobile money or card and enter the amount.');
        }
        if ($method === 'momo' && (! array_key_exists((string) $provider, SalePayment::MOMO_PROVIDERS) || strlen(trim((string) $reference)) < 4)) {
            throw new DomainException('Choose the mobile money network and enter the transaction ID.');
        }
    }

    private function supervisor(Device $device, User $user, Branch $branch, ?array $approval): string
    {
        if ($this->authz->allows($user, 'restaurant.void', $branch)) {
            return $user->name;
        }
        if (! $approval) {
            throw new DomainException('A supervisor must approve giving the deposit back.', 'refund_needs_approval');
        }

        return $this->approvals->verify($device, $approval, 'restaurant.void', $branch, null, 0, $user->id)->name;
    }

    private function tillBranch(Device $device, User $user, Reservation $r): Branch
    {
        $branch = Branch::findOrFail($device->branch_id);
        if ($r->branch_id !== $branch->id) {
            throw new DomainException('That booking is at another branch.');
        }
        $this->require(Actor::user($user, 'pos', $device->id), $branch, 'restaurant.serve');

        return $branch;
    }

    private function require(Actor $actor, Branch $branch, string $permission): void
    {
        if (! $this->modules->isEnabled($branch, 'restaurant')) {
            throw new DomainException('The Restaurant module is not on at this branch.', 'module_disabled');
        }
        if (! $actor->user || ! $this->authz->allows($actor->user, $permission, $branch)) {
            throw new AccessDenied;
        }
    }

    private function tz(Branch $branch): string
    {
        return $branch->timezone ?: (string) config('app.timezone');
    }
}
