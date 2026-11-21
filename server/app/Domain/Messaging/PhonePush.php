<?php

namespace App\Domain\Messaging;

use App\Models\PushSubscription;
use App\Models\User;
use App\Support\NotifyPrefs;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Notifications on a person's phone or computer, even with the back office closed (standard Web Push; owner request
 * 2026-11-15, for a till asking a manager who is not at the till). Each browser the person allowed gets them; the
 * message carries only the title, a line of text and the page to open, never figures beyond what the bell shows.
 * Sent after the reply (the till does not wait); a browser that has gone away is forgotten.
 */
final class PhonePush
{
    /** For tests: what would have been sent. */
    public static ?array $sent = null;

    /** The server's public key, which a browser needs to subscribe. Made once per company. */
    public function publicKey(string $tenantId): string
    {
        return $this->keys($tenantId)['publicKey'];
    }

    public function subscribe(User $user, string $endpoint, string $publicKey, string $authToken, ?string $device): PushSubscription
    {
        if (! Str::startsWith($endpoint, 'https://')) {
            throw new \InvalidArgumentException('Not a push address.');
        }
        $hash = hash('sha256', $endpoint);
        $s = PushSubscription::withoutGlobalScopes()->where('endpoint_hash', $hash)->first() ?? new PushSubscription(['endpoint_hash' => $hash]);
        $s->fill(['endpoint' => $endpoint, 'public_key' => $publicKey, 'auth_token' => $authToken, 'device' => $device ? mb_substr($device, 0, 255) : null, 'user_id' => $user->id]);
        $s->tenant_id = $user->tenant_id;
        $s->save();

        return $s;
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::withoutGlobalScopes()->where('user_id', $user->id)->where('endpoint_hash', hash('sha256', $endpoint))->delete();
    }

    public static function hasDevice(User $user): bool
    {
        return PushSubscription::withoutGlobalScopes()->where('user_id', $user->id)->exists();
    }

    /** @param Collection<int, User> $users */
    public function send(Collection $users, string $title, ?string $body, ?string $url, string $topic, ?string $tag = null): int
    {
        $users = $users->filter(fn (User $u) => NotifyPrefs::wants($u, $topic, 'push'));
        if ($users->isEmpty()) {
            return 0;
        }
        $subs = PushSubscription::withoutGlobalScopes()->whereIn('user_id', $users->modelKeys())->get();
        if ($subs->isEmpty()) {
            return 0;
        }
        $payload = json_encode(['title' => $title, 'body' => Str::limit((string) $body, 300), 'url' => $url, 'tag' => $tag ?? $topic,
            'urgent' => in_array($topic, ['approvals', 'security'], true)]);
        if (self::$sent !== null) {
            self::$sent[] = ['users' => $subs->pluck('user_id')->unique()->values()->all(), 'title' => $title, 'body' => $body, 'url' => $url, 'devices' => $subs->count()];

            return $subs->count();
        }
        $tenantId = $subs->first()->tenant_id;
        defer(fn () => $this->deliver($tenantId, $subs, $payload));

        return $subs->count();
    }

    /** @param Collection<int, PushSubscription> $subs */
    private function deliver(string $tenantId, Collection $subs, string $payload): void
    {
        try {
            $keys = $this->keys($tenantId);
            $push = new WebPush(['VAPID' => ['subject' => rtrim((string) config('app.url'), '/') ?: 'mailto:support@localhost', 'publicKey' => $keys['publicKey'],
                'privateKey' => $keys['privateKey']]], ['TTL' => 600, 'urgency' => 'high'], 10);
            foreach ($subs as $s) {
                $push->queueNotification(Subscription::create(['endpoint' => $s->endpoint, 'publicKey' => $s->public_key, 'authToken' => $s->auth_token,
                    'contentEncoding' => 'aes128gcm']), $payload);
            }
            foreach ($push->flush() as $report) {
                $hash = hash('sha256', $report->getEndpoint());
                if ($report->isSuccess()) {
                    PushSubscription::withoutGlobalScopes()->where('endpoint_hash', $hash)->update(['last_sent_at' => now()]);
                } elseif ($report->isSubscriptionExpired()) {
                    PushSubscription::withoutGlobalScopes()->where('endpoint_hash', $hash)->delete(); // the person removed it, or the browser is gone
                } else {
                    Log::warning('Push notification not delivered', ['reason' => $report->getReason()]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Push notifications could not be sent', ['error' => $e->getMessage()]);
        }
    }

    /** @return array{publicKey: string, privateKey: string} */
    /**
     * A new P-256 key pair as Web Push wants it (base64url: the 65-byte public point, the 32-byte private number), the same
     * as VAPID::createVapidKeys() makes. Made here with a configuration file shipped with the server, because Windows PHP
     * builds come without one and the library's way then fails with "Unable to create the key".
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public static function newKeys(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => resource_path('openssl/openssl.cnf')]);
        $ec = $key ? (openssl_pkey_get_details($key)['ec'] ?? null) : null;
        if (! $ec) {
            throw new \RuntimeException('Could not make the notification key: '.(openssl_error_string() ?: 'OpenSSL refused.'));
        }
        $pad = fn (string $b) => str_pad($b, 32, "\0", STR_PAD_LEFT);
        $b64 = fn (string $b) => rtrim(strtr(base64_encode($b), '+/', '-_'), '=');

        return ['publicKey' => $b64("\x04".$pad($ec['x']).$pad($ec['y'])), 'privateKey' => $b64($pad($ec['d']))];
    }

    private function keys(string $tenantId): array
    {
        $row = DB::table('web_push_keys')->where('tenant_id', $tenantId)->first();
        if (! $row) {
            $new = self::newKeys();
            DB::table('web_push_keys')->insertOrIgnore(['id' => (string) Str::uuid7(), 'tenant_id' => $tenantId, 'public_key' => $new['publicKey'],
                'private_key' => Crypt::encryptString($new['privateKey']), 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('web_push_keys')->where('tenant_id', $tenantId)->first();
        }

        return ['publicKey' => $row->public_key, 'privateKey' => Crypt::decryptString($row->private_key)];
    }
}
