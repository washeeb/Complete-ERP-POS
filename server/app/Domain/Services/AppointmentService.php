<?php

namespace App\Domain\Services;

use App\Domain\Messaging\Messenger;
use App\Domain\Modules\ModuleService;
use App\Domain\Support\AccessDenied;
use App\Domain\Support\Actor;
use App\Domain\Support\DocumentNumbers;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Security\Authorizer;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Appointments (Services): a service booked with a staff member at a time. One person cannot be booked twice at once.
 * The customer arrives, the service is done and paid at the till (the sale marks the appointment done and credits the
 * staff member's commission), or the booking ends as a no-show or is cancelled. SMS confirmations and reminders go out
 * through the same SMS gateway hook as restaurant bookings.
 */
final class AppointmentService
{
    public const TRANSITIONS = ['booked' => ['arrived', 'no_show', 'cancelled'], 'arrived' => ['booked', 'cancelled']];

    public function __construct(
        private readonly Recorder $recorder,
        private readonly Authorizer $authz,
        private readonly DocumentNumbers $numbers,
        private readonly ModuleService $modules,
        private readonly Settings $settings,
    ) {}

    /** @param array{branch_id?: string, employee_id?: string, product_id?: string, starts_at?: string, duration_minutes?: ?int, customer_id?: ?string, customer_name?: ?string, phone?: ?string, note?: ?string} $data */
    public function book(Actor $actor, Appointment $a, array $data): Appointment
    {
        $branch = Branch::findOrFail($data['branch_id'] ?? $a->branch_id);
        $this->require($actor, $branch);
        if ($a->exists && $a->status !== 'booked') {
            throw new DomainException('Only an appointment that has not started can be changed.');
        }
        $service = Product::where('is_active', true)->whereNotNull('service_minutes')->find($data['product_id'] ?? $a->product_id)
            ?? throw new DomainException('Choose the service (a product with a duration, under Product → Services).');
        $employee = Employee::where('branch_id', $branch->id)->where('status', 'active')->where('takes_appointments', true)->find($data['employee_id'] ?? $a->employee_id)
            ?? throw new DomainException('Choose who will do it (staff of this branch who take appointments).');
        $starts = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : $a->starts_at;
        $minutes = (int) ($data['duration_minutes'] ?? 0) ?: (int) $service->service_minutes;
        if (! $starts || $minutes < 5 || $minutes > 720) {
            throw new DomainException('Enter the date and time (and a length of 5 minutes to 12 hours).');
        }
        if ((! $a->exists || $a->isDirty('starts_at') || isset($data['starts_at'])) && $starts->lt(now()->subMinutes(5))) {
            throw new DomainException('That time has already passed.');
        }
        $customer = ! empty($data['customer_id']) ? Customer::find($data['customer_id']) ?? throw new DomainException('That customer is not known here.') : null;
        $name = trim((string) ($data['customer_name'] ?? '') ?: ($customer?->name ?? $a->customer_name ?? ''));
        $phone = array_key_exists('phone', $data) ? (preg_replace('/[^0-9+]/', '', (string) $data['phone']) ?: null) : ($a->phone ?? $customer?->phone);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new DomainException('Enter the customer’s name.');
        }
        if ($phone !== null && (strlen($phone) < 9 || strlen($phone) > 20)) {
            throw new DomainException('Check the phone number.');
        }
        $ends = $starts->copy()->addMinutes($minutes);
        $isNew = ! $a->exists;

        return $this->recorder->transaction(function () use ($actor, $a, $branch, $service, $employee, $starts, $ends, $customer, $name, $phone, $data, $isNew) {
            Employee::lockForUpdate()->findOrFail($employee->id); // one booking at a time per person, so two tills cannot double-book them
            $clash = Appointment::where('employee_id', $employee->id)->whereIn('status', Appointment::OPEN)->when(! $isNew, fn ($q) => $q->whereKeyNot($a->id))
                ->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)->orderBy('starts_at')->first();
            if ($clash) {
                $tz = $this->tz($branch);
                throw new DomainException("{$employee->name} is booked {$clash->starts_at->setTimezone($tz)->format('H:i')}–{$clash->ends_at->setTimezone($tz)->format('H:i')} ({$clash->customer_name}).", 'staff_busy');
            }
            $a->forceFill(['branch_id' => $branch->id, 'employee_id' => $employee->id, 'product_id' => $service->id, 'starts_at' => $starts, 'ends_at' => $ends,
                'customer_name' => $name, 'phone' => $phone, 'note' => array_key_exists('note', $data) ? (mb_substr(trim((string) $data['note']), 0, 255) ?: null) : $a->note]
                + ($customer ? ['customer_id' => $customer->id] : []));
            if ($isNew) {
                $a->forceFill(['tenant_id' => $actor->tenantId, 'number' => $this->numbers->next($actor->tenantId, 'appointment'), 'status' => 'booked',
                    'created_by_name' => $actor->name]);
            }
            if (! $isNew && $a->isDirty('starts_at')) {
                $a->reminder_sent_at = null; // a new time gets a new reminder
            }

            return $this->recorder->save($actor, $a, $isNew ? 'appointment.booked' : 'appointment.changed', branchId: $branch->id);
        });
    }

    public function setStatus(Actor $actor, Appointment $a, string $status, ?string $reason = null): Appointment
    {
        $this->require($actor, Branch::findOrFail($a->branch_id));
        if (! in_array($status, self::TRANSITIONS[$a->status] ?? [], true)) {
            throw new DomainException('That appointment cannot be marked '.strtolower(Appointment::STATUSES[$status] ?? $status).' now.', 'wrong_status');
        }
        if ($status === 'no_show' && $a->starts_at->isFuture()) {
            throw new DomainException('It is too early to mark a no-show.');
        }
        $a->status = $status;

        return $this->recorder->save($actor, $a, "appointment.{$status}", $reason, $a->branch_id);
    }

    /** Till line for an appointment being paid: it must be open at this branch (offline: a review item instead). */
    public function forLine(array $l, Branch $branch, bool $offline, array &$flags): ?Appointment
    {
        if (empty($l['appointment_id'])) {
            return null;
        }
        $a = Appointment::where('branch_id', $branch->id)->find($l['appointment_id']);
        if (! $a || ! in_array($a->status, Appointment::OPEN, true)) {
            $offline ? $flags[] = ['appointment_conflict', 'Paid offline for an appointment that was not open'] : throw new DomainException('That appointment is no longer open.', 'appointment_invalid');
        }

        return $a;
    }

    /** After the sale is recorded: the appointments it paid for are done. */
    public function paidBySale(Actor $actor, Sale $sale, array $ids): void
    {
        foreach (array_unique($ids) as $id) {
            $a = Appointment::lockForUpdate()->find($id);
            if ($a && in_array($a->status, Appointment::OPEN, true)) {
                $a->forceFill(['status' => 'done', 'sale_id' => $sale->id]);
                $this->recorder->save($actor, $a, 'appointment.done', branchId: $a->branch_id, payload: ['sale_id' => $sale->id]);
            }
        }
    }

    /** One branch's day: appointments in time order (and the staff who take them). */
    public static function day(string $branchId, string $date, string $tz): array
    {
        $start = Carbon::parse($date, $tz)->startOfDay();

        return Appointment::with('employee:id,name', 'product:id,name')->where('branch_id', $branchId)->where('starts_at', '>=', $start->copy()->utc())
            ->where('starts_at', '<', $start->copy()->addDay()->utc())->whereNot('status', 'cancelled')->orderBy('starts_at')->get()->all();
    }

    /** Staff of a branch who take appointments (the till's "done by" list and the calendar's columns). */
    public static function staff(string $branchId)
    {
        return Employee::where('branch_id', $branchId)->where('status', 'active')->where('takes_appointments', true)->orderBy('name')->get(['id', 'name', 'job_title']);
    }

    /** Reminders due: booked appointments starting within the reminder window that have a phone and were not reminded yet. */
    public function queueReminders(): int
    {
        $n = 0;
        foreach (Tenant::all() as $tenant) {
            $hours = (int) $this->settings->get('services.reminder_hours', $tenant->id);
            if ($hours <= 0 || (SmsGateway::url($this->settings, $tenant->id) === '' && ! app(Messenger::class)->canText($tenant->id))) {
                continue;
            }
            $due = Appointment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'booked')->whereNotNull('phone')->whereNull('reminder_sent_at')
                ->whereBetween('starts_at', [now(), now()->addHours($hours)])->where('created_at', '<', now()->subHour())->get();
            foreach ($due as $a) {
                $a->forceFill(['reminder_sent_at' => now()]);
                $this->recorder->save(Actor::system($tenant->id, 'Appointment reminders', 'system'), $a, 'appointment.reminder', branchId: $a->branch_id);
                $n++;
            }
        }

        return $n;
    }

    private function require(Actor $actor, Branch $branch): void
    {
        if (! $this->modules->isEnabled($branch, 'services')) {
            throw new DomainException('The Services module is not on at this branch.', 'module_disabled');
        }
        if (! $actor->user || ! $this->authz->allows($actor->user, 'services.appointments', $branch)) {
            throw new AccessDenied;
        }
    }

    private function tz(Branch $branch): string
    {
        return $branch->timezone ?: (Tenant::find($branch->tenant_id)?->timezone ?: 'UTC');
    }
}
