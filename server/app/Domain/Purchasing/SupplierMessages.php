<?php

namespace App\Domain\Purchasing;

use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\Phone;
use App\Domain\Messaging\Templates;
use App\Domain\Support\DomainException;

/**
 * Sending a supplier a link (a request for quotation, their portal): by e-mail or WhatsApp through the business's
 * providers, or by WhatsApp from this computer (WhatsApp opens with the message ready; the person presses send).
 * The wording is under Messages → Templates; every message sent by the system is in the message log.
 */
final class SupplierMessages
{
    public function __construct(private readonly Messenger $messenger) {}

    /** The channels this business can use now: WhatsApp by the system needs a WhatsApp provider (System → Messaging). */
    public function channels(string $tenantId): array
    {
        return $this->messenger->provider($tenantId, 'whatsapp') ? OrderSender::CHANNELS : array_diff_key(OrderSender::CHANNELS, ['whatsapp' => 1]);
    }

    /** @return ?string the WhatsApp address to open on this computer (whatsapp_link), else null (sent) */
    public function send(string $tenantId, string $key, string $channel, string $to, array $vars, string $reference, string $by): ?string
    {
        if (! array_key_exists($channel, $this->channels($tenantId))) {
            throw new DomainException('Choose e-mail or WhatsApp.');
        }
        if ($channel === 'email' ? ! filter_var($to, FILTER_VALIDATE_EMAIL) : ! Phone::looksValid($to)) {
            throw new DomainException($channel === 'email' ? 'That e-mail address does not look right.' : 'That phone number does not look right.');
        }
        if ($channel === 'whatsapp_link') {
            $template = Templates::find($tenantId, $key, 'whatsapp') ?? throw new DomainException('The wording for this message is missing (Messages → Templates).');
            [, $body] = Templates::render($template, $vars);

            return 'https://wa.me/'.ltrim(Phone::international($to), '+').'?text='.rawurlencode(trim($body."\n\n".($vars['note'] ?? '')));
        }
        $template = Templates::find($tenantId, $key, $channel);
        if (! $template?->is_active) {
            throw new DomainException('This message by '.($channel === 'email' ? 'e-mail' : 'WhatsApp').' is switched off (Messages → Templates).');
        }
        $this->messenger->fromTemplate($tenantId, $channel, $to, $template, $vars, $reference, $by);

        return null;
    }
}
