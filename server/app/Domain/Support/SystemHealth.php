<?php

namespace App\Domain\Support;

use App\Domain\Fiscal\GraEvatAdapter;
use App\Domain\Licensing\LicenseManager;
use App\Models\BackupRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The launch-checklist health checks, run by `erp:health` (exit code for monitoring) and shown under System → Health.
 * Each check is ok, warning or fail, with what to do in plain words.
 */
final class SystemHealth
{
    /** @return list<array{check: string, status: string, detail: string}> */
    public function run(): array
    {
        $out = [];
        $add = function (string $check, string $status, string $detail) use (&$out) {
            $out[] = compact('check', 'status', 'detail');
        };

        try {
            $v = DB::selectOne('SHOW server_version')->server_version;
            $add('Database', version_compare($v, '16', '>=') ? 'ok' : 'warning', "PostgreSQL {$v}".(version_compare($v, '16', '>=') ? '' : ' — version 17 is recommended'));
        } catch (Throwable $e) {
            $add('Database', 'fail', 'Not reachable: '.$e->getMessage());

            return $out;
        }
        $beat = self::lastBeat('scheduler');
        $add('Scheduler', $beat && now()->diffInMinutes($beat) < 5 ? 'ok' : 'fail', $beat ? 'Last ran '.now()->diffForHumans($beat, true).' ago' : 'Not running: start the scheduler (Chapter 4/5) — nothing is posted, synced, sent or backed up without it.');
        $relay = self::lastBeat('relay');
        $add('Relay (live updates, messages, webhooks)', $relay && now()->diffInMinutes($relay) < 3 ? 'ok' : 'warning', $relay ? 'Last ran '.now()->diffForHumans($relay, true).' ago' : 'Not seen yet: it starts with the scheduler.');

        $last = BackupRun::latest('started_at')->first();
        $ok = BackupRun::where('status', 'ok')->latest('started_at')->first();
        $add('Backups', ! $ok ? 'fail' : ($ok->started_at->lt(now()->subHours(26)) ? 'fail' : ($last?->status === 'ok' ? 'ok' : 'warning')),
            ! $ok ? 'No successful backup yet: set the passphrase and a destination (System → Backups).'
                : 'Last good backup '.$ok->started_at->diffForHumans().($last && $last->status !== 'ok' ? "; the latest one is {$last->status}: {$last->error}" : ''));
        $verified = BackupRun::whereNotNull('verified_at')->latest('verified_at')->first();
        $add('Restore check', ! $verified ? 'warning' : (str_starts_with((string) $verified->verify_result, 'FAILED') ? 'fail' : ($verified->verified_at->lt(now()->subDays(8)) ? 'warning' : 'ok')),
            $verified ? $verified->verified_at->diffForHumans().': '.$verified->verify_result : 'No backup has been checked yet (weekly, or System → Backups → Check the newest backup).');
        $add('Backup passphrase', mb_strlen((string) config('backup.passphrase')) >= 12 ? 'ok' : 'fail', mb_strlen((string) config('backup.passphrase')) >= 12 ? 'Set' : 'Missing: run php artisan erp:backup:key');

        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($free !== false && $total) {
            $pct = $free / $total * 100;
            $add('Disk space', $free < 2 * 1024 ** 3 || $pct < 10 ? 'fail' : ($pct < 20 ? 'warning' : 'ok'), number_format($free / 1024 ** 3, 1).' GB free ('.round($pct).' %)');
        }
        // Load and memory (Linux servers): a server that is short of either slows every till at peak hours.
        if (function_exists('sys_getloadavg') && ($load = @sys_getloadavg()) && is_readable('/proc/cpuinfo')) {
            $cpus = max(1, substr_count((string) @file_get_contents('/proc/cpuinfo'), "\nprocessor") + (str_starts_with((string) @file_get_contents('/proc/cpuinfo'), 'processor') ? 1 : 0));
            $per = $load[1] / $cpus; // the 5-minute average, per processor
            $add('Server load', $per > 1.5 ? 'fail' : ($per > 0.8 ? 'warning' : 'ok'), sprintf('%.2f over the last 5 minutes on %d processor(s)', $load[1], $cpus)
                .($per > 0.8 ? ' — busy: look at Slow pages below, or move to a larger server.' : ''));
        }
        if (is_readable('/proc/meminfo') && preg_match('/MemTotal:\s+(\d+).*?MemAvailable:\s+(\d+)/s', (string) @file_get_contents('/proc/meminfo'), $m)) {
            $pct = (int) $m[2] / max(1, (int) $m[1]) * 100;
            $add('Memory', $pct < 5 ? 'fail' : ($pct < 15 ? 'warning' : 'ok'), number_format((int) $m[2] / 1024 ** 2, 1).' GB free of '.number_format((int) $m[1] / 1024 ** 2, 1).' GB ('.round($pct).' %)');
        }
        $add('Live site settings', config('app.debug') ? 'fail' : (config('app.env') !== 'production' ? 'warning' : 'ok'),
            config('app.debug') ? 'APP_DEBUG is true: set it to false (it shows technical details to visitors).' : 'APP_ENV='.config('app.env'));
        $add('HTTPS', str_starts_with((string) config('app.url'), 'https://') ? 'ok' : 'warning', str_starts_with((string) config('app.url'), 'https://')
            ? 'APP_URL uses https' : 'APP_URL is not https — required when the server is reachable from the internet (setup C/D).');
        $add('E-mail', config('mail.default') === 'log' ? 'warning' : 'ok', config('mail.default') === 'log' ? 'E-mails are only written to the log (MAIL_MAILER=log).' : 'Sending through '.config('mail.default'));
        try {
            $lic = app(LicenseManager::class)->status();
            $add('Licence', $lic['read_only'] ? 'fail' : (($lic['state'] ?? '') === 'active' ? 'ok' : 'warning'), (string) ($lic['message'] ?: ($lic['state'] ?? '')));
        } catch (Throwable $e) {
            $add('Licence', 'warning', $e->getMessage());
        }

        // GRA E-VAT: when a company reports, GRA's VSDC must answer its health check (key and company reference right).
        $reporting = DB::table('settings')->where('key', 'evat.mode')->whereRaw("value::text <> '\"off\"'")->exists();
        if ($reporting && (string) config('fiscal.gra.url') !== '') {
            $up = Cache::remember('gra-evat-health', 300, fn () => app(GraEvatAdapter::class)->health()); // at most one call to GRA every 5 minutes
            $add('GRA E-VAT', $up ? 'ok' : 'fail', $up ? 'GRA’s VSDC answers' : 'GRA’s VSDC does not answer or refuses this server’s key: documents wait and are sent when it does (check GRA_EVAT_URL, GRA_EVAT_COMPANY_REFERENCE, GRA_EVAT_KEY).');
        }

        // Mobile money: payment requests chosen in Settings, but no provider to send them through.
        $wantsMomo = DB::table('settings')->where('key', 'momo.confirm')->whereRaw("value::text <> '\"manual\"'")->pluck('tenant_id');
        if ($wantsMomo->isNotEmpty()) {
            $missing = $wantsMomo->diff(DB::table('momo_gateways')->where('is_active', true)->pluck('tenant_id'));
            $add('Mobile money requests', $missing->isEmpty() ? 'ok' : 'warning', $missing->isEmpty() ? 'Provider set up'
                : 'Payment requests are chosen in Settings but no provider is set up (System → Mobile money → Payment provider): tills take typed transaction IDs meanwhile.');
        }

        $stuck = [
            'Messages waiting' => DB::table('messages')->where('status', 'queued')->where('created_at', '<', now()->subHour())->count(),
            'Webhooks given up' => DB::table('webhook_deliveries')->where('status', 'dead')->where('updated_at', '>', now()->subDay())->count(),
            'GRA E-VAT waiting over a day' => DB::table('fiscal_submissions')->where('status', 'pending')->where('created_at', '<', now()->subDay())->count(),
            'GRA E-VAT refused' => DB::table('fiscal_submissions')->where('status', 'rejected')->count(),
            'Changes not yet synced' => DB::table('outbox_events')->whereNull('published_at')->where('occurred_at', '<', now()->subMinutes(30))->count(),
        ];
        foreach ($stuck as $what => $n) {
            $add($what, $n ? 'warning' : 'ok', (string) $n);
        }

        // Speed: PHP's code cache (checked from the web server, where it matters), and pages slower than they should be.
        if (PHP_SAPI !== 'cli') {
            $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
            $add('PHP code cache (OPcache)', $opcache && ($opcache['opcache_enabled'] ?? false) ? 'ok' : 'warning',
                $opcache && ($opcache['opcache_enabled'] ?? false) ? 'On' : 'Off: pages are several times slower. Run the installer again (Chapter 12.4) to switch it on.');
        }
        $slow = DB::table('slow_requests')->where('created_at', '>=', now()->subDay())->count();
        $add('Slow pages (last 24 hours)', $slow > 50 ? 'warning' : 'ok', $slow ? "{$slow} took over ".number_format((int) config('erp.slow_request_ms', 1000) / 1000, 1).' s; see below' : 'None');

        return $out;
    }

    /** Stored as a plain timestamp: Laravel refuses to rebuild objects from a file or database cache (serializable_classes). */
    public static function beat(string $what): void
    {
        Cache::put("erp:heartbeat:{$what}", now()->getTimestamp(), now()->addDay());
    }

    /** When it last ran, or null (never, or an older value the cache cannot read back: the next beat replaces it). */
    public static function lastBeat(string $what): ?Carbon
    {
        $at = Cache::get("erp:heartbeat:{$what}");

        return is_int($at) ? Carbon::createFromTimestamp($at, config('app.timezone')) : null;
    }
}
