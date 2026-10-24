<?php

namespace App\Domain\Messaging;

use App\Domain\Support\DomainException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The SMS and WhatsApp providers, each in its own request format. The client chooses one per channel
 * (owner decision 2026-10-28: Hubtel, Arkesel, mNotify or a generic webhook for SMS; WhatsApp Business through Meta's
 * Cloud API). A provider answer that is not a success throws, and the message is retried later.
 */
final class Gateways
{
    public const SMS = ['hubtel' => 'Hubtel', 'arkesel' => 'Arkesel', 'mnotify' => 'mNotify', 'webhook' => 'Other gateway (webhook)'];

    /** Credential fields each provider needs: key => label. */
    public const FIELDS = [
        'hubtel' => ['client_id' => 'Client ID', 'client_secret' => 'Client secret'],
        'arkesel' => ['api_key' => 'API key'],
        'mnotify' => ['api_key' => 'API key'],
        'webhook' => ['url' => 'Gateway address (https://…)', 'secret' => 'Shared secret (optional, sent as X-Signature)'],
        'meta' => ['phone_number_id' => 'Phone number ID', 'access_token' => 'Access token', 'app_secret' => 'App secret (to receive messages)'],
    ];

    /** @return string the provider's message reference */
    public static function sms(string $provider, array $cred, ?string $sender, string $to, string $body, ?string $reference): string
    {
        $intl = Phone::international($to);
        $res = self::call(fn () => match ($provider) {
            'hubtel' => Http::withBasicAuth($cred['client_id'] ?? '', $cred['client_secret'] ?? '')->acceptJson()->timeout(15)
                ->post('https://smsc.hubtel.com/v1/messages/send', ['From' => $sender, 'To' => $intl, 'Content' => $body, 'ClientReference' => $reference]),
            'arkesel' => Http::withHeaders(['api-key' => $cred['api_key'] ?? ''])->acceptJson()->timeout(15)
                ->post('https://sms.arkesel.com/api/v2/sms/send', ['sender' => $sender, 'message' => $body, 'recipients' => [$intl]]),
            'mnotify' => Http::acceptJson()->timeout(15)->post('https://api.mnotify.com/api/sms/quick?key='.urlencode($cred['api_key'] ?? ''),
                ['recipient' => [$to], 'sender' => $sender, 'message' => $body, 'is_schedule' => false, 'schedule_date' => '']),
            'webhook' => Http::acceptJson()->timeout(10)->withHeaders(! empty($cred['secret'])
                ? ['X-Signature' => hash_hmac('sha256', json_encode(['to' => $to, 'message' => $body, 'reference' => $reference]), $cred['secret'])] : [])
                ->post($cred['url'] ?? '', ['to' => $to, 'message' => $body, 'reference' => $reference]),
            default => throw new DomainException("Unknown SMS provider {$provider}."),
        });
        // Some providers answer 200 with an error inside.
        $ok = match ($provider) {
            'arkesel' => ($res->json('status') ?? 'success') === 'success',
            'mnotify' => in_array((string) ($res->json('status') ?? 'success'), ['success', '2000'], true) || (string) $res->json('code') === '2000',
            default => true,
        };
        if (! $ok) {
            throw new DomainException(Str::limit((string) ($res->json('message') ?? $res->body()), 200));
        }

        return (string) ($res->json('MessageId') ?? $res->json('messageId') ?? $res->json('data.0.id') ?? $res->json('summary._id') ?? $res->json('id') ?? '');
    }

    /** WhatsApp Business (Meta Cloud API): business-started messages must use an approved template. */
    public static function whatsapp(array $cred, string $to, string $template, string $language, array $params): string
    {
        $res = self::call(fn () => Http::withToken($cred['access_token'] ?? '')->acceptJson()->timeout(15)
            ->post('https://graph.facebook.com/v20.0/'.($cred['phone_number_id'] ?? '').'/messages', ['messaging_product' => 'whatsapp', 'to' => Phone::international($to),
                'type' => 'template', 'template' => ['name' => $template, 'language' => ['code' => $language],
                    'components' => $params ? [['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $params)]] : []]]));

        return (string) ($res->json('messages.0.id') ?? '');
    }

    /** A plain WhatsApp reply: allowed by Meta within 24 hours of the customer's last message to the business. */
    public static function whatsappText(array $cred, string $to, string $body): string
    {
        $res = self::call(fn () => Http::withToken($cred['access_token'] ?? '')->acceptJson()->timeout(15)
            ->post('https://graph.facebook.com/v20.0/'.($cred['phone_number_id'] ?? '').'/messages', ['messaging_product' => 'whatsapp', 'to' => Phone::international($to),
                'type' => 'text', 'text' => ['body' => mb_substr($body, 0, 4000)]]));

        return (string) ($res->json('messages.0.id') ?? '');
    }

    /**
     * A photo or document a customer sent: Meta gives its address, which is fetched with the same token.
     *
     * @return array{mime: string, bytes: string}
     */
    public static function whatsappMedia(array $cred, string $mediaId, int $maxBytes): array
    {
        $info = self::call(fn () => Http::withToken($cred['access_token'] ?? '')->acceptJson()->timeout(15)->get('https://graph.facebook.com/v20.0/'.rawurlencode($mediaId)));
        if ((int) $info->json('file_size') > $maxBytes) {
            throw new DomainException('The file is larger than '.round($maxBytes / 1048576).' MB.');
        }
        $url = (string) $info->json('url');
        if (! str_starts_with($url, 'https://')) {
            throw new DomainException('WhatsApp did not give the file\'s address.');
        }
        $file = self::call(fn () => Http::withToken($cred['access_token'] ?? '')->timeout(30)->get($url));
        if (strlen($file->body()) > $maxBytes) {
            throw new DomainException('The file is larger than '.round($maxBytes / 1048576).' MB.');
        }

        return ['mime' => (string) ($info->json('mime_type') ?? $file->header('Content-Type')), 'bytes' => $file->body()];
    }

    private static function call(callable $request): Response
    {
        try {
            $res = $request();
        } catch (Throwable $e) {
            throw new DomainException('Provider not reachable: '.Str::limit($e->getMessage(), 150));
        }
        if (! $res->successful()) {
            throw new DomainException("Provider answered {$res->status()}: ".Str::limit((string) ($res->json('message') ?? $res->json('error.message') ?? $res->body()), 150));
        }

        return $res;
    }
}
