<?php

namespace App\Domain\Licensing;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What identifies the server a licence is bound to: the operating system's machine id and the database cluster's
 * identifier, hashed. Copying the application and its database to another machine changes it; the licence then
 * asks to be re-activated (with a grace period, as hardware is sometimes replaced legitimately).
 */
final class MachineFingerprint
{
    private static ?string $cached = null;

    public static function get(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $parts = array_values(array_filter([self::osId(), self::databaseId()]));

        return self::$cached = $parts ? substr(hash('sha256', implode('|', $parts)), 0, 32) : 'unknown';
    }

    public static function forget(): void
    {
        self::$cached = null;
    }

    private static function osId(): ?string
    {
        foreach (['/etc/machine-id', '/var/lib/dbus/machine-id'] as $f) {
            if (@is_readable($f) && ($v = trim((string) @file_get_contents($f))) !== '') {
                return $v;
            }
        }
        if (PHP_OS_FAMILY === 'Windows' && function_exists('shell_exec')) {
            $out = (string) @shell_exec('reg query HKLM\SOFTWARE\Microsoft\Cryptography /v MachineGuid 2>NUL');
            if (preg_match('/MachineGuid\s+REG_SZ\s+([0-9a-f-]{36})/i', $out, $m)) {
                return strtolower($m[1]);
            }
        }
        if (PHP_OS_FAMILY === 'Darwin' && function_exists('shell_exec')) {
            $out = (string) @shell_exec('ioreg -rd1 -c IOPlatformExpertDevice 2>/dev/null');
            if (preg_match('/"IOPlatformUUID" = "([^"]+)"/', $out, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private static function databaseId(): ?string
    {
        try {
            return (string) DB::selectOne('SELECT system_identifier::text AS id FROM pg_control_system()')->id ?: null;
        } catch (Throwable) {
            return null; // not allowed for this database user: the OS id alone is used
        }
    }
}
