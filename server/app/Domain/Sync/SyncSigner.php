<?php

namespace App\Domain\Sync;

use App\Domain\Support\DomainException;

/**
 * Node-to-node request signing: HMAC-SHA256 over time, method, path and the body hash, with the
 * secret shared when the branch server was registered at HQ. Requests older than 5 minutes are
 * refused; pushes are idempotent anyway, pulls are reads.
 */
final class SyncSigner
{
    public const MAX_SKEW = 300;

    public static function sign(string $secret, int $time, string $method, string $path, string $body): string
    {
        return hash_hmac('sha256', $time."\n".strtoupper($method)."\n".'/'.ltrim($path, '/')."\n".hash('sha256', $body), $secret);
    }

    public static function verify(string $secret, ?string $time, string $method, string $path, string $body, ?string $signature): void
    {
        if (! $time || ! ctype_digit($time) || abs(time() - (int) $time) > self::MAX_SKEW) {
            throw new DomainException('Sync request time is missing or more than 5 minutes off. Check both servers’ clocks.', 'bad_signature');
        }
        if (! $signature || ! hash_equals(self::sign($secret, (int) $time, $method, $path, $body), $signature)) {
            throw new DomainException('Sync request signature does not match.', 'bad_signature');
        }
    }

    /** Signature for an exported sync file (USB fallback). */
    public static function signFile(string $secret, array $events, array $header): string
    {
        return hash_hmac('sha256', json_encode($header).'|'.hash('sha256', json_encode($events)), $secret);
    }
}
