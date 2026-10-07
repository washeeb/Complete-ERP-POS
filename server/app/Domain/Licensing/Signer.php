<?php

namespace App\Domain\Licensing;

/**
 * Ed25519 signatures over canonical JSON (keys sorted, no escaped slashes), as used by licence keys, check-in
 * replies, release manifests and build stamps.
 */
final class Signer
{
    public static function canonical(array $data): string
    {
        $sort = function ($v) use (&$sort) {
            if (is_array($v)) {
                if (! array_is_list($v)) {
                    ksort($v);
                }

                return array_map($sort, $v);
            }

            return $v;
        };

        return json_encode($sort($data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    public static function sign(array $data, string $secretKey): string
    {
        return self::b64(sodium_crypto_sign_detached(self::canonical($data), $secretKey));
    }

    public static function verify(array $data, string $signature, string $publicKey): bool
    {
        $sig = self::unb64($signature);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, self::canonical($data), $publicKey);
    }

    /** Public keys this build trusts: the embedded production keys, plus the development key in development builds. */
    public static function trustedKeys(): array
    {
        $keys = [];
        foreach ((array) config('license.public_keys') as $kid => $b64) {
            $k = base64_decode((string) $b64, true);
            if ($k !== false) {
                $keys[$kid] = $k;
            }
        }
        if (! ReleaseManifest::isRelease() && ($dev = base64_decode((string) config('license.dev_public_key'), true))) {
            $keys['dev'] = $dev;
        }

        return $keys;
    }

    public static function verifyWithTrusted(array $data, string $signature, ?string $kid): bool
    {
        $keys = self::trustedKeys();

        return $kid !== null && isset($keys[$kid]) && self::verify($data, $signature, $keys[$kid]);
    }
}
