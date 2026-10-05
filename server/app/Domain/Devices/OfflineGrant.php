<?php

namespace App\Domain\Devices;

use App\Domain\Support\DomainException;
use App\Models\Device;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A signed, expiring permission for one person to use one till without the server.
 * Issued at an online PIN sign-in (the only moment the server sees the PIN); it carries a slow
 * PBKDF2 hash so the till can check the PIN offline. The PIN unlocks the grant — no reusable
 * password is cached. Revocation takes effect at the next sync (spec §4.2, docs/security.md).
 */
final class OfflineGrant
{
    public const ITERATIONS = 210_000;

    public function __construct(private readonly Settings $settings) {}

    public function issue(User $user, Device $device, string $pin): array
    {
        $salt = random_bytes(16);
        $grant = [
            'v' => 1,
            'id' => (string) Str::uuid7(),
            'tenant_id' => $device->tenant_id,
            'device_id' => $device->id,
            'till_id' => $device->till_id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'username' => $user->username,
            'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => now()->utc()->addHours((int) $this->settings->get('security.offline_grant_hours', $user->tenant_id))->format('Y-m-d\TH:i:s\Z'),
            'pin' => [
                'alg' => 'PBKDF2-SHA256',
                'iterations' => self::ITERATIONS,
                'salt' => base64_encode($salt),
                'hash' => base64_encode(hash_pbkdf2('sha256', $pin, $salt, self::ITERATIONS, 32, true)),
            ],
        ];
        $grant['sig'] = $this->sign($grant);

        return $grant;
    }

    /** @return array the grant, if the signature is ours. Expiry is checked by the caller (offline work after expiry is reviewed, not lost). */
    public function verify(mixed $grant): array
    {
        if (! is_array($grant) || ! isset($grant['sig'], $grant['v'], $grant['expires_at'], $grant['user_id'], $grant['device_id'])) {
            throw new DomainException('Missing or malformed offline sign-in.', 'invalid_grant');
        }
        if (! hash_equals($this->sign($grant), (string) $grant['sig'])) {
            throw new DomainException('The offline sign-in was not issued by this system.', 'invalid_grant');
        }

        return $grant;
    }

    public function expiresAt(array $grant): Carbon
    {
        return Carbon::parse($grant['expires_at']);
    }

    private function sign(array $grant): string
    {
        unset($grant['sig']);
        ksort($grant);
        $key = hash_hmac('sha256', 'erp-offline-grant-v1', (string) config('app.key'), true);

        return hash_hmac('sha256', json_encode($grant, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $key);
    }
}
