<?php

namespace App\Domain\Support;

use App\Domain\Licensing\LicenseManager;
use App\Models\BackupRun;
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
        $beat = Cache::get('erp:heartbeat:scheduler');
        $add('Scheduler', $beat && now()->diffInMinutes($beat) < 5 ? 'ok' : 'fail', $beat ? 'Last ran '.now()->diffForHumans($beat, true).' ago' : 'Not running: start the scheduler (Chapter 4/5) — nothing is posted, synced, sent or backed up without it.');
        $relay = Cache::get('erp:heartbeat:relay');
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

    public static function beat(string $what): void
    {
        Cache::put("erp:heartbeat:{$what}", now(), now()->addDay());
    }
}
