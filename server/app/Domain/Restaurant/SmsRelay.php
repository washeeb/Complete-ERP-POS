<?php

namespace App\Domain\Restaurant;

use App\Domain\Channels\OutboxConsumers;
use App\Domain\Support\Actor;
use App\Domain\Support\Recorder;
use App\Models\Branch;
use App\Models\OutboxEvent;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * SMS hook for bookings (owner decision 2026-10-18): a confirmation when a table is booked and a reminder before it,
 * posted to the SMS gateway's address (Settings → Restaurant) as {"to", "message", "reference"}. Any SMS provider (or a
 * small bridge in front of one) can take it; no provider account is built in. A gateway that is down is retried.
 */
final class SmsRelay
{
    public function __construct(private readonly OutboxConsumers $consumers, private readonly Settings $settings, private readonly Recorder $recorder) {}

    public function run(): int
    {
        return $this->consumers->consume('sms', function (OutboxEvent $e) {
            $url = trim((string) $this->settings->get('restaurant.sms_webhook_url', $e->tenant_id));
            $row = $e->payload['snapshot']['row'] ?? [];
            if ($url === '' || empty($row['phone']) || ($row['status'] ?? '') !== 'booked') {
                return;
            }
            if ($e->event_type === 'reservation.booked' && ! $this->settings->get('restaurant.sms_confirmations', $e->tenant_id)) {
                return;
            }
            $branch = Branch::withoutGlobalScopes()->find($row['branch_id']);
            $tenant = Tenant::find($e->tenant_id);
            $at = Carbon::parse($row['starts_at'])->setTimezone($branch?->timezone ?: ($tenant->timezone ?: 'UTC'));
            $who = $tenant->name.($branch ? " {$branch->name}" : '');
            $message = $e->event_type === 'reservation.reminder'
                ? "{$who}: reminder of your table for {$row['party_size']} today at {$at->format('H:i')} (booking {$row['number']})."
                : "{$who}: table for {$row['party_size']} booked for {$at->format('D j M, H:i')} (booking {$row['number']}).".($branch?->phone ? " To change it call {$branch->phone}." : '');
            $res = Http::timeout(10)->acceptJson()->post($url, ['to' => $row['phone'], 'message' => $message, 'reference' => $row['number']]);
            if ($res->failed()) {
                throw new RuntimeException("SMS gateway answered {$res->status()}."); // the cursor stays: retried next run
            }
        }, 200, fn (OutboxEvent $e) => $e->aggregate_type === 'reservation' && in_array($e->event_type, ['reservation.booked', 'reservation.reminder'], true));
    }

    /** Reminders due: bookings starting within the reminder window that have a phone and were not reminded yet. */
    public function queueReminders(): int
    {
        $n = 0;
        foreach (Tenant::all() as $tenant) {
            $hours = (int) $this->settings->get('restaurant.reminder_hours', $tenant->id);
            if ($hours <= 0 || trim((string) $this->settings->get('restaurant.sms_webhook_url', $tenant->id)) === '') {
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
