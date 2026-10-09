<?php

namespace App\Domain\Licensing;

/**
 * A licence key: "SYN1-<payload>.<signature>" (base64url). The payload says who may run the software, on which
 * installation, until when and with what limits:
 *
 *   {v, kid, license_id, customer, installation_id, fingerprint, issued_at, expires_at, grace_days, checkin_days,
 *    server_url, limits: {branches, devices, users}, features: [finance, hr, …], modules: [grocery, …], dev?}
 *
 * A limit of null means unlimited.
 */
final class LicenseKey
{
    public static function encode(array $payload, string $signature): string
    {
        return 'SYN1-'.Signer::b64(Signer::canonical($payload)).'.'.$signature;
    }

    /** @return array{payload: array, signature: string} */
    public static function decode(string $key): array
    {
        $key = preg_replace('/\s+/', '', $key);
        if (! str_starts_with($key, 'SYN1-') || substr_count($key, '.') !== 1) {
            throw new LicenseException('That is not a licence key for this software.', 'license_invalid');
        }
        [$p, $sig] = explode('.', substr($key, 5));
        $json = Signer::unb64($p);
        $payload = $json === false ? null : json_decode($json, true);
        if (! is_array($payload) || ($payload['v'] ?? null) !== 1 || empty($payload['license_id']) || empty($payload['expires_at'])) {
            throw new LicenseException('The licence key is damaged. Copy it again exactly as sent.', 'license_invalid');
        }

        return ['payload' => $payload, 'signature' => $sig];
    }

    /** Decode and check the signature against the keys this build trusts. */
    public static function verify(string $key): array
    {
        $k = self::decode($key);
        if (! Signer::verifyWithTrusted($k['payload'], $k['signature'], $k['payload']['kid'] ?? null)) {
            throw new LicenseException('This licence key was not issued by '.config('brand.vendor').' (signature check failed).', 'license_invalid');
        }

        return $k['payload'];
    }
}
