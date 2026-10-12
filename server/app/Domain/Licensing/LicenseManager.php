<?php

namespace App\Domain\Licensing;

use App\Domain\Branding\Branding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The licence state of this installation (owner decision 2026-10-15):
 *
 *   unlicensed  — not activated: nothing can be changed or printed until a key is entered
 *   active      — all good
 *   warning     — expiry within 30 days, licence server not reached for a while, or the server was moved
 *   grace       — expired less than the grace period ago (14 days): everything still works, with a banner
 *   read_only   — after the grace period, or revoked, or a tampered release: data can be viewed on screen only —
 *                 no changes, no sales, no printing, no exports. Nothing is ever deleted or hidden.
 *
 * Head office checks that the licence belongs to this installation and machine and that the licence server has
 * been reached recently; branch servers take the licence head office sends them.
 */
final class LicenseManager
{
    private static array $memo = [];

    private static int $bypass = 0;

    /** Run something the licence must never block (activation, installation, receiving sync). */
    public static function bypass(callable $fn): mixed
    {
        self::$bypass++;
        try {
            return $fn();
        } finally {
            self::$bypass--;
        }
    }

    public static function forget(): void
    {
        self::$memo = [];
        ReleaseManifest::forget();
    }

    /** Called by the Recorder before every change. */
    public function assertWritable(): void
    {
        if (self::$bypass > 0) {
            return;
        }
        $s = $this->status();
        if ($s['read_only']) {
            throw new LicenseException($s['message'], $s['state'] === 'unlicensed' ? 'license_missing' : 'license_read_only');
        }
    }

    public function canOutput(): bool
    {
        return ! $this->status()['read_only'];
    }

    public function allowsFeature(string $feature): bool
    {
        $f = $this->status()['features'];

        return $f === null || in_array($feature, $f, true);
    }

    public function allowsModule(string $module): bool
    {
        $m = $this->status()['modules'];

        return $m === null || in_array($module, $m, true);
    }

    /** Refuse going over a licensed count (branches, devices, users). */
    public function assertWithin(string $limit, int $current, string $what): void
    {
        $max = $this->status()['limits'][$limit] ?? null;
        if ($max !== null && $current >= (int) $max) {
            throw new LicenseException("Your licence allows {$max} {$what}. Contact ".Branding::vendorContact().' to add more.', 'license_limit');
        }
    }

    /**
     * @return array{state: string, read_only: bool, message: ?string, license_id: ?string, short: ?string, customer: ?string, expires_at: ?string,
     *               days_left: ?int, limits: array, features: ?array, modules: ?array, checkin_url: ?string, problems: list<string>}
     */
    public function status(): array
    {
        $key = DB::getDefaultConnection().'|'.config('erp.node.id');
        if (isset(self::$memo[$key]) && self::$memo[$key][0] > microtime(true)) {
            return self::$memo[$key][1];
        }
        $status = $this->compute();
        self::$memo[$key] = [microtime(true) + 60, $status];

        return $status;
    }

    private function compute(): array
    {
        $base = ['license_id' => null, 'short' => null, 'customer' => null, 'expires_at' => null, 'days_left' => null, 'limits' => [], 'features' => null,
            'modules' => null, 'checkin_url' => null, 'problems' => []];
        try {
            $row = DB::table('licenses')->where('is_current', true)->orderByDesc('activated_at')->first();
        } catch (Throwable) {
            return $base + ['state' => 'unlicensed', 'read_only' => true, 'message' => 'The licence tables are missing; run the database migrations.'];
        }
        if (! $row) {
            return $base + ['state' => 'unlicensed', 'read_only' => true,
                'message' => 'This installation is not activated. Enter the licence key from '.Branding::vendorContact().' (Settings → Licence).'];
        }
        try {
            $p = LicenseKey::verify($row->license_key);
        } catch (LicenseException $e) {
            return $base + ['state' => 'read_only', 'read_only' => true, 'message' => $e->getMessage()];
        }

        $now = now();
        $grace = (int) ($p['grace_days'] ?? 14);
        $expires = Carbon::parse($p['expires_at']);
        $status = ['license_id' => $p['license_id'], 'short' => strtoupper(substr(str_replace('-', '', $p['license_id']), -8)), 'customer' => $p['customer'] ?? null,
            'expires_at' => $expires->toIso8601String(), 'days_left' => (int) floor($now->diffInDays($expires, false)), 'limits' => $p['limits'] ?? [],
            'features' => $p['features'] ?? null, 'modules' => $p['modules'] ?? null, 'checkin_url' => $p['server_url'] ?? null] + $base;

        $readOnly = [];
        $warnings = [];
        if ($now->gte($expires->copy()->addDays($grace))) {
            $readOnly[] = 'The licence expired on '.$expires->format('j M Y').' and the grace period has ended. Renew it to continue — you can still view your data.';
        } elseif ($now->gte($expires)) {
            $warnings[] = 'The licence expired on '.$expires->format('j M Y').'. Everything keeps working until '.$expires->copy()->addDays($grace)->format('j M Y').'; renew before then.';
        } elseif ($now->gte($expires->copy()->subDays((int) config('license.warn_days', 30)))) {
            $warnings[] = 'The licence expires on '.$expires->format('j M Y').'. Contact '.Branding::vendorContact().' to renew.';
        }
        if ($row->revoked_at || $row->checkin_status === 'revoked') {
            $readOnly[] = 'This licence was withdrawn by '.config('brand.vendor').($row->checkin_message ? ": {$row->checkin_message}" : '.');
        }
        $manifest = ReleaseManifest::check();
        if (! $manifest['ok']) {
            $readOnly[] = 'This copy of the software has been modified, so it is read-only. Reinstall the release from '.Branding::vendorContact().'.';
            $status['problems'] = $manifest['problems'];
        }

        if (config('erp.node.role') !== 'branch') {
            $installation = DB::table('system_meta')->where('key', 'installation_id')->value('value');
            if (($p['installation_id'] ?? null) !== $installation) {
                $readOnly[] = 'This licence was issued for a different installation. Ask '.Branding::vendorContact().' for a key for this one (Settings → Licence shows its code).';
            }
            $fp = MachineFingerprint::get();
            if (! empty($p['fingerprint']) && $p['fingerprint'] !== 'unknown' && $fp !== 'unknown' && $p['fingerprint'] !== $fp) {
                $since = DB::table('system_meta')->where('key', 'moved_detected_at')->value('value');
                if (! $since) {
                    $since = $now->toIso8601String();
                    DB::table('system_meta')->updateOrInsert(['key' => 'moved_detected_at'], ['value' => $since, 'updated_at' => $now]);
                }
                $until = Carbon::parse($since)->addDays($grace);
                $msg = 'The software is now running on a different server. Ask '.Branding::vendorContact().' to re-activate it (Settings → Licence)';
                $now->gte($until) ? $readOnly[] = "{$msg}." : $warnings[] = "{$msg} before ".$until->format('j M Y').'.';
            }
            if (! empty($p['server_url']) && ! empty($p['checkin_days'])) {
                $last = Carbon::parse($row->last_checkin_at ?? $row->activated_at);
                $due = $last->copy()->addDays((int) $p['checkin_days']);
                if ($now->gte($due->copy()->addDays($grace))) {
                    $readOnly[] = 'The licence server has not been reached since '.$last->format('j M Y').'. Connect head office to the internet to continue.';
                } elseif ($now->gte($due)) {
                    $warnings[] = 'The licence server has not been reached since '.$last->format('j M Y').'. Connect head office to the internet before '.$due->copy()->addDays($grace)->format('j M Y').'.';
                }
            }
        }

        if ($readOnly) {
            return ['state' => 'read_only', 'read_only' => true, 'message' => $readOnly[0]] + $status;
        }
        if ($warnings) {
            return ['state' => $now->gte($expires) ? 'grace' : 'warning', 'read_only' => false, 'message' => $warnings[0]] + $status;
        }

        return ['state' => 'active', 'read_only' => false, 'message' => null] + $status;
    }
}
