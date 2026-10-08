<?php

namespace App\Domain\Restaurant;

use App\Domain\Channels\OutboxConsumers;
use App\Domain\Messaging\Messenger;
use App\Domain\Services\SmsGateway;
use App\Domain\Support\Actor;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\Settings;
use Illuminate\Support\Carbon;

/**
 * Booking messages (owner decision 2026-10-18) and, since Phase 10c, appointments: a confirmation when a table or an
 * appointment is booked and a reminder before it. Since Phase 12 they go through the Messenger (WhatsApp or SMS provider,
 * wording under Messaging → Templates, retried when the provider is down); a module's own SMS webhook address from
 * earlier versions is still used when no SMS provider is set up.
 */
final class SmsRelay
{
    public function __construct(private readonly OutboxConsumers $consumers, private readonly Settings $settings, private readonly Recorder $recorder,
        private readonly Messenger $messenger) {}

    public function run(): int
    {
        return $this->consumers->consume('sms', function (OutboxEvent $e) {
            if ($e->aggregate_type === 'appointment') {
                return $this->appointment($e);
            }
            $row = $e->payload['snapshot']['row'] ?? [];
            if (empty($row['phone']) || ($row['status'] ?? '') !== 'booked') {
                return;
            }
            if ($e->event_type === 'reservation.booked' && ! $this->settings->get('restaurant.sms_confirmations', $e->tenant_id)) {
                return;
            }
            [$who, $at, $branch] = $this->context($e->tenant_id, $row);
            $this->messenger->toCustomer($e->tenant_id, $e->event_type === 'reservation.reminder' ? 'reservation_reminder' : 'reservation_booked', $row['phone'], null,
                ['who' => $who, 'party_size' => $row['party_size'], 'when' => $at->format('D j M, H:i'), 'time' => $at->format('H:i'), 'number' => $row['number'],
                    'call' => $branch?->phone ? " To change it call {$branch->phone}." : ''], $row['number'], 'Booking messages',
                trim((string) $this->settings->get('restaurant.sms_webhook_url', $e->tenant_id)) ?: null);
        }, 200, fn (OutboxEvent $e) => ($e->aggregate_type === 'reservation' && in_array($e->event_type, ['reservation.booked', 'reservation.reminder'], true))
            || ($e->aggregate_type === 'appointment' && in_array($e->event_type, ['appointment.booked', 'appointment.reminder'], true)));
    }

    /** Services: "booked" (when confirmations are on) and "reminder" messages for an appointment. */
    private function appointment(OutboxEvent $e): void
    {
        $row = $e->payload['snapshot']['row'] ?? [];
        if (empty($row['phone']) || ($row['status'] ?? '') !== 'booked'
            || ($e->event_type === 'appointment.booked' && ! $this->settings->get('services.sms_confirmations', $e->tenant_id))) {
            return;
        }
        [$who, $at, $branch] = $this->context($e->tenant_id, $row);
        $this->messenger->toCustomer($e->tenant_id, $e->event_type === 'appointment.reminder' ? 'appointment_reminder' : 'appointment_booked', $row['phone'], null,
            ['who' => $who, 'service' => (string) Product::withoutGlobalScopes()->whereKey($row['product_id'])->value('name'), 'when' => $at->format('D j M, H:i'),
                'time' => $at->format('H:i'), 'number' => $row['number'], 'call' => $branch?->phone ? " To change it call {$branch->phone}." : ''], $row['number'], 'Appointment messages',
            SmsGateway::url($this->settings, $e->tenant_id) ?: null);
    }

    /** @return array{0: string, 1: Carbon, 2: ?Branch} who the message is from, the local start time, the branch */
    private function context(string $tenantId, array $row): array
    {
        $branch = Branch::withoutGlobalScopes()->find($row['branch_id']);
        $tenant = Tenant::find($tenantId);

        return [$tenant->name.($branch ? " {$branch->name}" : ''), Carbon::parse($row['starts_at'])->setTimezone($branch?->timezone ?: ($tenant->timezone ?: 'UTC')), $branch];
    }

    /** Reminders due: bookings starting within the reminder window that have a phone and were not reminded yet. */
    public function queueReminders(): int
    {
        $n = 0;
        foreach (Tenant::all() as $tenant) {
            $hours = (int) $this->settings->get('restaurant.reminder_hours', $tenant->id);
            if ($hours <= 0 || (trim((string) $this->settings->get('restaurant.sms_webhook_url', $tenant->id)) === '' && ! $this->messenger->canText($tenant->id))) {
                continue;
            }
            $due = Reservation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'booked')->whereNotNull('phone')->whereNull('reminder_sent_at')
                ->whereBetween('starts_at', [now(), now()->addHours($hours)])->where('created_at', '<', now()->subHour())->get();
            foreach ($due as $r) {
                $r->forceFill(['reminder_sent_at' => now()]);
                $this->recorder->save(Actor::system($tenant->id, 'Booking reminders', 'system'), $r, 'reservation.reminder', branchId: $r->branch_id);
                $n++;
            }
        }

        return $n;
    }
}
