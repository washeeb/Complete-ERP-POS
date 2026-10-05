<?php

namespace App\Domain\Devices;

use App\Domain\Support\DomainException;
use App\Models\Device;

/**
 * Verifies that a sync batch was signed by the till's own non-extractable key (ECDSA P-256,
 * SHA-256). Browsers produce raw r||s (IEEE P1363) signatures; OpenSSL wants DER — converted here.
 * A stolen bearer token alone cannot submit sales.
 */
final class DeviceSignature
{
    public function verify(Device $device, string $body, ?string $signatureB64): void
    {
        $fail = new DomainException('The batch signature does not match this device.', 'bad_signature');
        if (! $device->public_key || ! $signatureB64) {
            throw $fail;
        }
        $raw = base64_decode($signatureB64, true);
        if ($raw === false || strlen($raw) !== 64) {
            throw $fail;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($device->public_key, 64, "\n").'-----END PUBLIC KEY-----';
        $key = openssl_pkey_get_public($pem);
        if ($key === false || openssl_verify($body, self::rawToDer($raw), $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw $fail;
        }
    }

    public static function rawToDer(string $raw): string
    {
        $int = function (string $b): string {
            $b = ltrim($b, "\x00");
            if ($b === '' || ord($b[0]) & 0x80) {
                $b = "\x00".$b;
            }

            return "\x02".chr(strlen($b)).$b;
        };
        $seq = $int(substr($raw, 0, 32)).$int(substr($raw, 32, 32));

        return "\x30".chr(strlen($seq)).$seq;
    }

    public static function derToRaw(string $der): string
    {
        $pos = 2;
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$pos + 1]);
            $parts[] = str_pad(ltrim(substr($der, $pos + 2, $len), "\x00"), 32, "\x00", STR_PAD_LEFT);
            $pos += 2 + $len;
        }

        return $parts[0].$parts[1];
    }
}
