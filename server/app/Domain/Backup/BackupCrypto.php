<?php

namespace App\Domain\Backup;

use App\Domain\Support\DomainException;

/**
 * Backup files are encrypted with the backup passphrase (.env BACKUP_PASSPHRASE): a key is derived with Argon2id and the
 * file is sealed in chunks with XChaCha20-Poly1305 (libsodium secretstream), so a stolen USB drive or a leaked cloud
 * bucket reveals nothing, and any change to the file is detected. Without the passphrase a backup cannot be restored —
 * keep a copy of it away from the server (see the installation guide).
 *
 * Layout: "SYNBAK1\n" · 16-byte salt · 24-byte stream header · chunks (each 1 MiB of plaintext + 17 bytes).
 */
final class BackupCrypto
{
    private const MAGIC = "SYNBAK1\n";

    private const CHUNK = 1048576;

    public static function encrypt(string $in, string $out, string $passphrase): void
    {
        self::requirePassphrase($passphrase);
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = self::key($passphrase, $salt);
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        $src = fopen($in, 'rb');
        $dst = fopen($out, 'wb');
        fwrite($dst, self::MAGIC.$salt.$header);
        $size = filesize($in);
        $done = 0;
        do {
            $chunk = (string) fread($src, self::CHUNK);
            $done += strlen($chunk);
            $tag = $done >= $size ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            fwrite($dst, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
        } while ($done < $size);
        fclose($src);
        fclose($dst);
        sodium_memzero($key);
    }

    public static function decrypt(string $in, string $out, string $passphrase): void
    {
        self::requirePassphrase($passphrase);
        $src = fopen($in, 'rb');
        if (fread($src, strlen(self::MAGIC)) !== self::MAGIC) {
            fclose($src);
            throw new DomainException('This is not a Synclytics backup file.');
        }
        $salt = fread($src, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $header = fread($src, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $key = self::key($passphrase, $salt);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $dst = fopen($out, 'wb');
        $final = false;
        while (! feof($src) && ! $final) {
            $cipher = fread($src, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ($cipher === '' || $cipher === false) {
                break;
            }
            $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
            if ($res === false) {
                fclose($src);
                fclose($dst);
                @unlink($out);
                throw new DomainException('The backup cannot be decrypted: wrong passphrase, or the file is damaged.');
            }
            [$plain, $tag] = $res;
            fwrite($dst, $plain);
            $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        fclose($src);
        fclose($dst);
        sodium_memzero($key);
        if (! $final) {
            @unlink($out);
            throw new DomainException('The backup file is incomplete (cut off while copying?).');
        }
    }

    private static function key(string $passphrase, string $salt): string
    {
        return sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, $passphrase, $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    }

    private static function requirePassphrase(string $passphrase): void
    {
        if (mb_strlen($passphrase) < 12) {
            throw new DomainException('Set a backup passphrase of at least 12 characters in .env (BACKUP_PASSPHRASE) — run php artisan erp:backup:key.');
        }
    }
}
