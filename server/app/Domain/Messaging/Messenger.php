<?php

namespace App\Domain\Messaging;

use App\Domain\Support\DomainException;
use App\Mail\PlainMessage;
use App\Models\Message;
use App\Models\MessageProvider;
use App\Models\MessageTemplate;
use App\Support\Settings;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends SMS, WhatsApp and e-mail, and keeps every message in the message log. A message is tried at once; if the
 * provider is down it waits and is retried (1, 5, 15, 60 minutes, then every 3 hours, up to 8 tries). Without a provider
 * set up, the message is logged as "not sent" so the business can see what would have gone out.
 *
 * E-mail goes through the server's mail settings (.env, any SMTP server). SMS through the provider chosen under
 * Messaging → Providers; for the restaurant and services messages, a module's own SMS webhook address (from earlier
 * versions) is still used when no SMS provider is set up.
 */
final class Messenger
{
    private const BACKOFF = [1, 5, 15, 60, 180, 180, 180];

    public const MAX_ATTEMPTS = 8;

    /**
     * Send a message from a template to a customer on the best channel: WhatsApp when it is set up and its template is
     * on, else SMS; plus e-mail when there is an address and the e-mail template is on.
     *
     * @return list<Message>
     */
    public function toCustomer(string $tenantId, string $key, ?string $phone, ?string $email, array $vars, ?string $reference, string $by, ?string $fallbackWebhook = null): array
    {
        $out = [];
        $phone = $phone && Phone::looksValid($phone) ? $phone : null; // a customer without a usable number simply gets no text
        $email = $email && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        if ($phone) {
            $wa = Templates::find($tenantId, $key, 'whatsapp');
            $useWa = $wa?->is_active && $this->provider($tenantId, 'whatsapp');
            $sms = Templates::find($tenantId, $key, 'sms');
            if ($useWa) {
                $out[] = $this->fromTemplate($tenantId, 'whatsapp', $phone, $wa, $vars, $reference, $by);
            } elseif ($sms?->is_active) {
                $out[] = $this->fromTemplate($tenantId, 'sms', $phone, $sms, $vars, $reference, $by, fallbackWebhook: $fallbackWebhook);
            }
        }
        $mail = $email ? Templates::find($tenantId, $key, 'email') : null;
        if ($mail?->is_active) {
            $out[] = $this->fromTemplate($tenantId, 'email', $email, $mail, $vars, $reference, $by);
        }

        return $out;
    }

    public function fromTemplate(string $tenantId, string $channel, string $to, MessageTemplate $t, array $vars, ?string $reference, string $by,
        ?string $attachmentPath = null, ?string $attachmentName = null, ?string $fallbackWebhook = null): Message
    {
        [$subject, $body, $params] = Templates::render($t, $vars);

        return $this->send($tenantId, $channel, $to, $body, $subject, $reference, $by, $t->key, $params, $attachmentPath, $attachmentName, $fallbackWebhook);
    }

    /** Log the message and try to deliver it now. */
    public function send(string $tenantId, string $channel, string $to, string $body, ?string $subject, ?string $reference, string $by, ?string $templateKey = null,
        array $params = [], ?string $attachmentPath = null, ?string $attachmentName = null, ?string $fallbackWebhook = null): Message
    {
        if (! array_key_exists($channel, Message::CHANNELS)) {
            throw new DomainException('Unknown channel.');
        }
        $to = trim($to);
        if ($channel === 'email' ? ! filter_var($to, FILTER_VALIDATE_EMAIL) : ! Phone::looksValid($to)) {
            throw new DomainException($channel === 'email' ? 'That e-mail address does not look right.' : 'That phone number does not look right.');
        }
        $m = Message::create(['tenant_id' => $tenantId, 'channel' => $channel, 'to' => mb_substr($to, 0, 160), 'template_key' => $templateKey,
            'subject' => $subject ? mb_substr($subject, 0, 255) : null, 'body' => $body, 'params' => $params, 'reference' => $reference ? mb_substr($reference, 0, 80) : null,
            'attachment_path' => $attachmentPath, 'attachment_name' => $attachmentName, 'status' => 'queued', 'sent_by_name' => mb_substr($by, 0, 120),
            'next_attempt_at' => now(), 'provider' => $fallbackWebhook ? 'webhook' : null]);
        $this->deliver($m, $fallbackWebhook);

        return $m->fresh();
    }

    /** Retry messages whose provider was down. Run by the relay every few seconds. */
    public function deliverDue(int $limit = 50): int
    {
        $n = 0;
        foreach (Message::withoutGlobalScopes()->where('status', 'queued')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->get() as $m) {
            $this->deliver($m, $m->provider === 'webhook' && $m->channel === 'sms' && ! $this->provider($m->tenant_id, 'sms') ? $this->legacyWebhook($m) : null);
            $n++;
        }

        return $n;
    }

    /** An SMS or WhatsApp provider is set up, so messages can reach phones. */
    public function canText(string $tenantId): bool
    {
        return (bool) ($this->provider($tenantId, 'sms') ?? $this->provider($tenantId, 'whatsapp'));
    }

    public function provider(string $tenantId, string $channel): ?MessageProvider
    {
        return MessageProvider::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('channel', $channel)->where('is_active', true)->first();
    }

    private function deliver(Message $m, ?string $fallbackWebhook): void
    {
        $provider = $m->channel === 'email' ? null : $this->provider($m->tenant_id, $m->channel);
        if ($m->channel !== 'email' && ! $provider && ! ($m->channel === 'sms' && $fallbackWebhook)) {
            $m->forceFill(['status' => 'not_configured', 'next_attempt_at' => null, 'error' => 'No '.Message::CHANNELS[$m->channel].' provider is set up (Messaging → Providers).'])->save();

            return;
        }
        $m->attempts++;
        try {
            $ref = match ($m->channel) {
                'email' => $this->email($m),
                'whatsapp' => Gateways::whatsapp($provider->credentials ?? [], $m->to, (string) (Templates::find($m->tenant_id, (string) $m->template_key, 'whatsapp')?->whatsapp_template ?? $m->template_key),
                    (string) (Templates::find($m->tenant_id, (string) $m->template_key, 'whatsapp')?->whatsapp_language ?? 'en_US'), $m->params ?? []),
                default => $provider
                    ? Gateways::sms($provider->provider, $provider->credentials ?? [], $provider->sender_id, $m->to, $m->body, $m->reference)
                    : Gateways::sms('webhook', ['url' => $fallbackWebhook], null, $m->to, $m->body, $m->reference),
            };
            $m->forceFill(['status' => 'sent', 'provider' => $m->channel === 'email' ? 'smtp' : ($provider?->provider ?? 'webhook'), 'provider_ref' => $ref ? mb_substr($ref, 0, 120) : null,
                'sent_at' => now(), 'next_attempt_at' => null, 'error' => null])->save();
        } catch (Throwable $e) {
            $last = $m->attempts >= self::MAX_ATTEMPTS;
            $m->forceFill(['status' => $last ? 'failed' : 'queued', 'error' => Str::limit($e->getMessage(), 250),
                'next_attempt_at' => $last ? null : now()->addMinutes(self::BACKOFF[min($m->attempts - 1, count(self::BACKOFF) - 1)])])->save();
        }
    }

    private function email(Message $m): string
    {
        Mail::to($m->to)->send(new PlainMessage((string) $m->subject, $m->body, $m->attachment_path, $m->attachment_name));

        return '';
    }

    /** The module SMS webhook (restaurant / services settings) a queued message was first sent through. */
    private function legacyWebhook(Message $m): ?string
    {
        $s = app(Settings::class);
        $url = str_starts_with((string) $m->template_key, 'appointment')
            ? trim((string) $s->get('services.sms_webhook_url', $m->tenant_id)) ?: trim((string) $s->get('restaurant.sms_webhook_url', $m->tenant_id))
            : trim((string) $s->get('restaurant.sms_webhook_url', $m->tenant_id));

        return $url ?: null;
    }
}
