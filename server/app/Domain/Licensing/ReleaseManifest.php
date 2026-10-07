<?php

namespace App\Domain\Licensing;

/**
 * Tamper detection for release builds. `release:build` hashes the files that enforce licensing and signs the list
 * with the Synclytics key; at run time the app checks the signature and the hashes. A changed file makes the
 * installation read-only and is reported at check-in. (A determined attacker with the source can still remove
 * checks; this raises the cost and leaves evidence — see docs/licensing.md.)
 */
final class ReleaseManifest
{
    public const PATH = 'storage/app/release/manifest.json';

    /** Files guarded in every release (relative to the application root). */
    public const PROTECTED = [
        'app/Domain/Licensing', 'app/Domain/Support/Recorder.php', 'app/Security/Authorizer.php', 'app/Http/Middleware/EnforceLicense.php',
        'app/Providers/Filament/AdminPanelProvider.php', 'bootstrap/app.php', 'config/license.php', 'routes',
    ];

    private static ?array $result = null;

    public static function isRelease(): bool
    {
        return is_file(base_path(self::PATH));
    }

    public static function data(): ?array
    {
        $raw = self::isRelease() ? json_decode((string) file_get_contents(base_path(self::PATH)), true) : null;

        return is_array($raw) ? $raw : null;
    }

    /** Every protected file with its SHA-256, in a stable order. */
    public static function hashes(string $root): array
    {
        $files = [];
        foreach (self::PROTECTED as $p) {
            $full = $root.DIRECTORY_SEPARATOR.$p;
            if (is_dir($full)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile()) {
                        $files[str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1))] = hash_file('sha256', $f->getPathname());
                    }
                }
            } elseif (is_file($full)) {
                $files[$p] = hash_file('sha256', $full);
            }
        }
        ksort($files);

        return $files;
    }

    /** @return array{release: bool, ok: bool, build_id: ?string, problems: list<string>} */
    public static function check(bool $fresh = false): array
    {
        if (self::$result !== null && ! $fresh) {
            return self::$result;
        }
        $m = self::data();
        if (! $m) {
            return self::$result = ['release' => false, 'ok' => true, 'build_id' => null, 'problems' => []];
        }
        $problems = [];
        $body = $m;
        unset($body['sig']);
        if (! Signer::verifyWithTrusted($body, (string) ($m['sig'] ?? ''), $m['kid'] ?? null) || ($m['kid'] ?? '') === 'dev') {
            $problems[] = 'The release manifest is not signed by Synclytics.';
        } else {
            $now = self::hashes(base_path());
            foreach ($m['files'] ?? [] as $path => $hash) {
                if (($now[$path] ?? null) !== $hash) {
                    $problems[] = "{$path} has been changed or removed.";
                }
            }
            foreach (array_diff_key($now, $m['files'] ?? []) as $path => $_) {
                $problems[] = "{$path} was added.";
            }
        }

        return self::$result = ['release' => true, 'ok' => $problems === [], 'build_id' => $m['build_id'] ?? null, 'problems' => $problems];
    }

    public static function forget(): void
    {
        self::$result = null;
    }
}
