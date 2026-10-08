<?php

namespace App\Domain\Services;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ServicePass;
use Illuminate\Support\Carbon;

/**
 * How a till sale meets Services: who did each service (commission), sessions paid from a customer's pass, appointments
 * paid for, prepaid packs sold (they become passes) and licence keys handed out. Returns undo the same things.
 */
final class ServiceSales
{
    public function __construct(
        private readonly PassService $passes,
        private readonly AppointmentService $appointments,
        private readonly CommissionService $commissions,
        private readonly LicenceKeyService $keys,
    ) {}

    /**
     * One till line's Services fields, checked.
     *
     * @return array{pass: ?array{pass: ServicePass, item: int}, staff_id: ?string, appointment_id: ?string}
     */
    public function line(array $l, ?Customer $customer, Product $product, int $qtyMilli, Branch $branch, Carbon $at, bool $offline, array &$flags): array
    {
        $staffId = null;
        if (! empty($l['staff_id'])) {
            $staff = Employee::find($l['staff_id']);
            if (! $staff || (! $offline && $staff->status !== 'active')) {
                $offline ? $flags[] = ['staff_unknown', "{$product->name}: done by someone not on the staff list"]
                    : throw new DomainException('Choose who did the service from the staff list.', 'staff_invalid');
            }
            $staffId = $staff?->id;
        }
        $appointment = $this->appointments->forLine($l, $branch, $offline, $flags);

        return ['pass' => $this->passes->forLine($l, $customer, $product, $qtyMilli, $at, $offline, $flags), 'staff_id' => $staffId ?? $appointment?->employee_id,
            'appointment_id' => $appointment?->id];
    }

    /** Inside the sale's transaction, after its lines are saved. */
    public function settle(Actor $actor, Sale $sale, array $appointmentIds, ?Customer $customer, bool $offline, array &$flags): void
    {
        $this->passes->settleSale($actor, $sale, $customer, $offline, $flags);
        $this->appointments->paidBySale($actor, $sale, $appointmentIds);
        $this->commissions->record($actor, $sale);
        $this->keys->handOut($actor, $sale, $offline, $flags);
    }

    /** Inside a return's transaction, after its lines are saved. */
    public function settleReturn(Actor $actor, Sale $return): void
    {
        $this->passes->settleReturn($actor, $return);
        $this->commissions->record($actor, $return);
        $this->keys->takeBack($actor, $return);
    }
}
