<?php

namespace App\Domain\Messaging;

use App\Domain\Support\Actor;
use App\Domain\Support\DomainException;
use App\Domain\Support\Recorder;
use App\Models\MessageTemplate;
use App\Models\Tenant;

/**
 * Message wording, editable under Messaging → Templates. Defaults are added for every company (missing rows only, so
 * edits are never overwritten). Placeholders in {braces} are filled in when a message goes out; unknown ones stay.
 * Messages to customers that the business may not want sent automatically start switched off.
 */
final class Templates
{
    /** key => [label, placeholders, [channel => [subject, body, active, whatsapp template]]] */
    public const DEFAULTS = [
        'receipt' => ['Receipt (sent from the till on request)', '{company} {branch} {receipt_no} {date} {total} {items} {lines}', [
            'sms' => [null, 'Thank you for shopping at {company} {branch}. Receipt {receipt_no} of {date}: {items} item(s), total {total}.', true, null],
            'whatsapp' => [null, 'Thank you for shopping at {company}. Receipt {receipt_no}, total {total}.', true, 'receipt'],
            'email' => ['Your receipt {receipt_no} from {company}', "Thank you for shopping at {company} {branch}.\n\nReceipt {receipt_no} — {date}\n\n{lines}\n\nTotal: {total}", true, null],
        ]],
        'reservation_booked' => ['Table booking confirmed', '{who} {party_size} {when} {number} {call}', [
            'sms' => [null, '{who}: table for {party_size} booked for {when} (booking {number}).{call}', true, null],
            'whatsapp' => [null, '{who}: table for {party_size} booked for {when} (booking {number}).', true, 'booking_confirmed'],
        ]],
        'reservation_reminder' => ['Table booking reminder', '{who} {party_size} {time} {number}', [
            'sms' => [null, '{who}: reminder of your table for {party_size} today at {time} (booking {number}).', true, null],
            'whatsapp' => [null, '{who}: reminder of your table for {party_size} today at {time} (booking {number}).', true, 'booking_reminder'],
        ]],
        'appointment_booked' => ['Appointment confirmed', '{who} {service} {when} {number} {call}', [
            'sms' => [null, '{who}: {service} booked for {when} ({number}).{call}', true, null],
            'whatsapp' => [null, '{who}: {service} booked for {when} ({number}).', true, 'appointment_confirmed'],
        ]],
        'appointment_reminder' => ['Appointment reminder', '{who} {service} {time} {number}', [
            'sms' => [null, '{who}: reminder of your {service} appointment today at {time} ({number}).', true, null],
            'whatsapp' => [null, '{who}: reminder of your {service} appointment today at {time} ({number}).', true, 'appointment_reminder'],
        ]],
        'invoice_issued' => ['Invoice sent to the customer', '{company} {customer} {number} {total} {due_on}', [
            'email' => ['Invoice {number} from {company}', "Dear {customer},\n\nPlease find our invoice {number} for {total}, due on {due_on}.\n\nThank you for your business.\n{company}", false, null],
            'sms' => [null, '{company}: invoice {number} for {total} is due on {due_on}. Thank you.', false, null],
        ]],
        'payment_received' => ['Payment received from a customer', '{company} {customer} {amount} {number} {balance}', [
            'sms' => [null, '{company}: we received {amount} ({number}). Balance now {balance}. Thank you.', false, null],
            'email' => ['Payment received — {number}', "Dear {customer},\n\nWe received {amount} ({number}). Your balance is now {balance}.\n\nThank you.\n{company}", false, null],
        ]],
        'scheduled_report' => ['Scheduled report', '{company} {report} {period} {rows}', [
            'email' => ['{report} — {period}', "Attached: {report} for {period} ({rows} row(s)).\n\nSent automatically by {company}'s Synclytics ERP. Change the schedule under Reports.", true, null],
            'whatsapp' => [null, '{company}: {report} for {period} is ready ({rows} row(s)). It was e-mailed to the recipients.', true, 'report_ready'],
        ]],
    ];

    public function __construct(private readonly Recorder $recorder) {}

    /** Add the default wording a company does not have yet. */
    public static function seed(Tenant $tenant): int
    {
        $n = 0;
        foreach (self::DEFAULTS as $key => [, , $channels]) {
            foreach ($channels as $channel => [$subject, $body, $active, $wa]) {
                $exists = MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('key', $key)->where('channel', $channel)->exists();
                if (! $exists) {
                    (new MessageTemplate)->forceFill(['tenant_id' => $tenant->id, 'key' => $key, 'channel' => $channel, 'subject' => $subject, 'body' => $body,
                        'is_active' => $active, 'whatsapp_template' => $wa, 'whatsapp_language' => $wa ? 'en' : null])->save();
                    $n++;
                }
            }
        }

        return $n;
    }

    public static function find(string $tenantId, string $key, string $channel): ?MessageTemplate
    {
        return MessageTemplate::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('key', $key)->where('channel', $channel)->first();
    }

    /** @return array{0: ?string, 1: string, 2: list<string>} subject, body, WhatsApp parameters (placeholder values in order of appearance) */
    public static function render(MessageTemplate $t, array $vars): array
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{'.$k.'}'] = (string) $v;
        }
        preg_match_all('/\{(\w+)\}/', $t->body, $m);
        $params = array_values(array_map(fn ($k) => (string) ($vars[$k] ?? ''), array_unique($m[1])));

        return [$t->subject === null ? null : strtr($t->subject, $map), strtr($t->body, $map), $params];
    }

    /** Change the wording (or switch a message on or off). */
    public function save(Actor $actor, MessageTemplate $t, array $data): MessageTemplate
    {
        $t->fill(array_intersect_key($data, array_flip(['subject', 'body', 'is_active', 'whatsapp_template', 'whatsapp_language'])));
        if (trim((string) $t->body) === '' || ($t->channel === 'email' && trim((string) $t->subject) === '')) {
            throw new DomainException('A message needs its text (and an e-mail its subject).');
        }
        if ($t->channel === 'sms' && mb_strlen($t->body) > 480) {
            throw new DomainException('Keep SMS text under 480 characters (3 SMS).');
        }
        if ($t->channel === 'whatsapp' && $t->is_active && trim((string) $t->whatsapp_template) === '') {
            throw new DomainException('WhatsApp messages need the name of the template approved by Meta.');
        }

        return $this->recorder->save($actor, $t, 'message_template.updated');
    }
}
