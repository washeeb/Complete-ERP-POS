<?php

namespace App\Domain\Backup;

use App\Domain\Support\DomainException;
use App\Models\BackupDestination;
use App\Models\BackupRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Automatic encrypted backups with tested restores (spec §11; owner decision 2026-10-29).
 *
 * A backup holds the whole database (pg_dump, custom format), the server's .env (its keys are needed to read encrypted
 * data after a restore) and the uploaded files (receipts, documents). It is encrypted with the backup passphrase,
 * kept on the server (the last few) and copied to every active destination, which keeps its own newest N.
 *
 * "Verify" decrypts a backup and checks every part (and that PostgreSQL can read the dump). "Restore drill" goes
 * further: it restores the dump into a scratch database and compares the row counts recorded when the backup was made —
 * proof that the backup really restores, without touching the live data.
 */
final class BackupService
{
    /** Tables whose row counts are recorded in each backup and compared by the restore drill. */
    public const COUNTED = ['tenants', 'users', 'products', 'customers', 'sales', 'sale_lines', 'stock_movements', 'journal_lines', 'audit_events', 'invoices'];

    public function run(string $by = 'Scheduled backup'): BackupRun
    {
        $lock = Cache::lock('erp-backup', 3600);
        if (! $lock->get()) {
            throw new DomainException('A backup is already running.');
        }
        $name = 'synclytics-'.Str::slug((string) config('erp.node.id', 'node')).'-'.now()->format('Ymd-His').'.synbak';
        $run = BackupRun::create(['file_name' => $name, 'status' => 'running', 'started_by' => mb_substr($by, 0, 120), 'started_at' => now()]);
        $work = $this->workDir($run->id);
        try {
            $dump = "{$work}/database.pgdump";
            $this->pg(['pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--file='.$dump]);
            $manifest = ['format' => 1, 'app' => config('app.name'), 'node' => config('erp.node.id'), 'role' => config('erp.node.role'),
                'database' => config('database.connections.pgsql.database'), 'created_at' => now()->toIso8601String(), 'dump_sha256' => hash_file('sha256', $dump),
                'counts' => $this->counts()];
            $zipPath = "{$work}/backup.zip";
            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFile($dump, 'database.pgdump');
            if (is_file(base_path('.env'))) {
                $zip->addFile(base_path('.env'), 'env/.env');
            }
            $files = storage_path('app/private');
            foreach (File::allFiles($files) as $f) {
                $rel = str_replace('\\', '/', $f->getRelativePathname());
                if (! str_starts_with($rel, 'backups/')) {
                    $zip->addFile($f->getPathname(), 'files/'.$rel);
                }
            }
            $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
            $zip->close();

            $out = $this->localDir().DIRECTORY_SEPARATOR.$name;
            BackupCrypto::encrypt($zipPath, $out, (string) config('backup.passphrase'));
            $run->forceFill(['size_bytes' => filesize($out), 'sha256' => hash_file('sha256', $out)]);

            $results = [];
            foreach (BackupDestination::where('is_active', true)->get() as $d) {
                try {
                    Destinations::put($d->type, $d->config, $out, $name);
                    $this->prune($d);
                    $d->forceFill(['last_ok_at' => now(), 'last_error' => null])->save();
                    $results[] = ['id' => $d->id, 'name' => $d->name, 'ok' => true, 'error' => null];
                } catch (Throwable $e) {
                    $d->forceFill(['last_error' => Str::limit($e->getMessage(), 250)])->save();
                    $results[] = ['id' => $d->id, 'name' => $d->name, 'ok' => false, 'error' => Str::limit($e->getMessage(), 250)];
                }
            }
            $this->pruneLocal();
            $failed = count(array_filter($results, fn ($r) => ! $r['ok']));
            $run->forceFill(['status' => $failed ? 'partial' : 'ok', 'destinations' => $results, 'finished_at' => now(),
                'error' => $failed ? "{$failed} destination(s) failed" : null])->save();
        } catch (Throwable $e) {
            $run->forceFill(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 250), 'finished_at' => now()])->save();

            throw $e instanceof DomainException ? $e : new DomainException('Backup failed: '.Str::limit($e->getMessage(), 200));
        } finally {
            File::deleteDirectory($work);
            $lock->release();
        }

        return $run;
    }

    /**
     * Decrypt a backup and check it: manifest, the dump's checksum, and that pg_restore can read the dump.
     *
     * @return array{manifest: array, entries: int}
     */
    public function verify(string $file): array
    {
        $work = $this->workDir('verify-'.Str::random(6));
        try {
            [$manifest, $dump, $zip] = $this->open($file, $work);
            $this->pg(['pg_restore', '--list', $dump], database: false);

            return ['manifest' => $manifest, 'entries' => $zip];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /**
     * Restore drill: restore the dump into a scratch database and compare the row counts recorded at backup time.
     *
     * @return array{ok: bool, counts: array<string, array{0: int, 1: int}>} table => [in backup, restored]
     */
    public function drill(string $file): array
    {
        $work = $this->workDir('drill-'.Str::random(6));
        $scratch = config('database.connections.pgsql.database').'_restore_drill';
        try {
            [$manifest, $dump] = $this->open($file, $work);
            // In its own sessions (psql), never the app's connection: a refused CREATE DATABASE must not disturb anything.
            try {
                $this->pg(['psql', '--no-psqlrc', '--quiet', '--command=DROP DATABASE IF EXISTS "'.$scratch.'"']);
                $this->pg(['psql', '--no-psqlrc', '--quiet', '--command=CREATE DATABASE "'.$scratch.'"']);
            } catch (DomainException) {
                throw new DomainException("The restore drill needs permission to create a scratch database ({$scratch}). Ask the database administrator to run: ALTER ROLE "
                    .config('database.connections.pgsql.username').' CREATEDB;');
            }
            $this->pg(['pg_restore', '--no-owner', '--no-privileges', '--exit-on-error', '--dbname='.$scratch, $dump], database: false);
            $tables = array_keys($manifest['counts'] ?? []);
            $sql = implode(' UNION ALL ', array_map(fn ($t) => "SELECT '{$t}', count(*) FROM \"{$t}\"", array_filter($tables, fn ($t) => preg_match('/^[a-z_]+$/', $t))));
            $restored = [];
            foreach (preg_split('/\R/', trim($this->pg(['psql', '--no-psqlrc', '--tuples-only', '--no-align', '--field-separator=|', '--command='.$sql, '--dbname='.$scratch], database: false))) as $line) {
                [$t, $n] = array_pad(explode('|', $line), 2, '0');
                $restored[$t] = (int) $n;
            }
            $counts = [];
            $ok = true;
            foreach ($manifest['counts'] ?? [] as $table => $n) {
                $counts[$table] = [(int) $n, $restored[$table] ?? -1];
                $ok = $ok && $counts[$table][0] === $counts[$table][1];
            }

            return ['ok' => $ok, 'counts' => $counts];
        } finally {
            try {
                $this->pg(['psql', '--no-psqlrc', '--quiet', '--command=DROP DATABASE IF EXISTS "'.$scratch.'"']);
            } catch (Throwable) {
                // nothing to drop
            }
            File::deleteDirectory($work);
        }
    }

    /** Verify (and drill when possible) the newest backup on the server; the result is kept on its run. */
    public function verifyLatest(bool $drill = true): BackupRun
    {
        $run = BackupRun::whereIn('status', ['ok', 'partial'])->latest('started_at')->first() ?? throw new DomainException('There is no backup to check yet.');
        $file = $this->localDir().DIRECTORY_SEPARATOR.$run->file_name;
        if (! is_file($file)) {
            throw new DomainException("{$run->file_name} is no longer on the server.");
        }
        try {
            $v = $this->verify($file);
            $result = 'Readable: '.count($v['manifest']['counts'] ?? []).' tables counted';
            if ($drill) {
                try {
                    $d = $this->drill($file);
                    $result = $d['ok'] ? 'Restore drill passed: every counted table matches' : 'Restore drill: counts differ — '.collect($d['counts'])
                        ->filter(fn ($c) => $c[0] !== $c[1])->map(fn ($c, $t) => "{$t} {$c[0]}→{$c[1]}")->implode(', ');
                } catch (DomainException $e) {
                    $result .= '; restore drill not run: '.$e->getMessage();
                }
            }
        } catch (DomainException $e) {
            $result = 'FAILED: '.$e->getMessage();
        }
        $run->forceFill(['verified_at' => now(), 'verify_result' => Str::limit($result, 250)])->save();

        return $run;
    }

    /**
     * Put a backup back (replaces the live database). The site is in maintenance mode meanwhile; the backup's .env is
     * saved next to the current one as .env.from-backup to compare keys.
     */
    public function restore(string $file): array
    {
        $work = $this->workDir('restore-'.Str::random(6));
        try {
            [$manifest, $dump] = $this->open($file, $work);
            Artisan::call('down', ['--retry' => 60]);
            try {
                $this->pg(['pg_restore', '--clean', '--if-exists', '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error',
                    '--dbname='.config('database.connections.pgsql.database'), $dump], database: false);
                if (is_dir("{$work}/files")) {
                    File::copyDirectory("{$work}/files", storage_path('app/private'));
                }
                if (is_file("{$work}/env/.env")) {
                    copy("{$work}/env/.env", base_path('.env.from-backup'));
                }
            } finally {
                Artisan::call('up');
            }

            return $manifest;
        } finally {
            File::deleteDirectory($work);
        }
    }

    public function localDir(): string
    {
        $dir = (string) config('backup.local_dir');
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /** @return array{0: array, 1: string, 2: int} manifest, dump path, number of entries */
    private function open(string $file, string $work): array
    {
        if (! is_file($file)) {
            throw new DomainException('Backup file not found.');
        }
        $zipPath = "{$work}/backup.zip";
        BackupCrypto::decrypt($file, $zipPath, (string) config('backup.passphrase'));
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new DomainException('The backup decrypts but its contents are damaged.');
        }
        $zip->extractTo($work);
        $entries = $zip->numFiles;
        $zip->close();
        $manifest = json_decode((string) @file_get_contents("{$work}/manifest.json"), true);
        $dump = "{$work}/database.pgdump";
        if (! is_array($manifest) || ! is_file($dump) || hash_file('sha256', $dump) !== ($manifest['dump_sha256'] ?? '')) {
            throw new DomainException('The backup is incomplete or its database part does not match its checksum.');
        }

        return [$manifest, $dump, $entries];
    }

    /** Run a PostgreSQL tool with the app's connection (password through the environment, never the command line); returns its output. */
    private function pg(array $args, bool $database = true): string
    {
        $c = config('database.connections.pgsql');
        $bin = rtrim((string) config('backup.pg_bin'), '\\/');
        $tool = ($bin !== '' ? $bin.DIRECTORY_SEPARATOR : '').array_shift($args).(PHP_OS_FAMILY === 'Windows' && $bin !== '' ? '.exe' : '');
        $conn = ['--host='.$c['host'], '--port='.$c['port'], '--username='.$c['username']];
        $process = new Process(array_merge([$tool], $conn, $args, $database ? ['--dbname='.$c['database']] : []), null,
            ['PGPASSWORD' => (string) $c['password'], 'PGCONNECT_TIMEOUT' => '15'], null, 3600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new DomainException(basename($tool).' failed: '.Str::limit(trim($process->getErrorOutput() ?: $process->getOutput()), 300)
                .(str_contains($process->getErrorOutput(), 'not recognized') || $process->getExitCode() === 127 ? ' — set BACKUP_PG_BIN in .env to PostgreSQL\'s bin folder.' : ''));
        }

        return $process->getOutput();
    }

    /** Row counts of key tables, read the way pg_dump reads them (its own session), for the restore drill to compare. @return array<string, int> */
    private function counts(): array
    {
        $out = [];
        $sql = implode(' UNION ALL ', array_map(fn ($t) => "SELECT '{$t}', count(*) FROM \"{$t}\"", self::COUNTED));
        foreach (preg_split('/\R/', trim($this->pg(['psql', '--no-psqlrc', '--tuples-only', '--no-align', '--field-separator=|', '--command='.$sql]))) as $line) {
            [$t, $n] = array_pad(explode('|', $line), 2, '0');
            $out[$t] = (int) $n;
        }

        return $out;
    }

    private function prune(BackupDestination $d): void
    {
        foreach (array_slice(Destinations::list($d->type, $d->config), max(1, $d->keep)) as $old) {
            Destinations::delete($d->type, $d->config, $old);
        }
    }

    private function pruneLocal(): void
    {
        $files = glob($this->localDir().DIRECTORY_SEPARATOR.'synclytics-*.synbak') ?: [];
        rsort($files);
        foreach (array_slice($files, max(1, (int) config('backup.keep_local'))) as $old) {
            @unlink($old);
        }
    }

    private function workDir(string $id): string
    {
        $dir = $this->localDir().DIRECTORY_SEPARATOR.'tmp-'.$id;
        File::ensureDirectoryExists($dir);

        return $dir;
    }
}
