<?php

namespace App\Domain\Licensing\Vendor;

use App\Domain\Licensing\LicenseException;

/** Synclytics' signing key (licence server / vendor workstation only; never shipped to customers). */
final class VendorKeys
{
    /** @return array{kid: string, secret: string} */
    public static function load(?string $path = null, ?string $kid = null): array
    {
        $path ??= config('license.server.private_key_path');
        $kid ??= config('license.server.kid');
        if (! $path || ! is_readable($path)) {
            throw new LicenseException('The Synclytics signing key is not available on this machine (LICENSE_PRIVATE_KEY_PATH).', 'license_vendor');
        }
        $data = json_decode((string) file_get_contents($path), true);
        $secret = base64_decode((string) ($data['secret'] ?? ''), true);
        if (! $secret || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new LicenseException('The signing key file is damaged.', 'license_vendor');
        }

        return ['kid' => $kid ?: (string) ($data['kid'] ?? 'syn'), 'secret' => $secret];
    }

    /** @return array{kid: string, secret: string, public: string} base64 strings */
    public static function generate(string $kid): array
    {
        $pair = sodium_crypto_sign_keypair();

        return ['kid' => $kid, 'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)), 'public' => base64_encode(sodium_crypto_sign_publickey($pair))];
    }
}
